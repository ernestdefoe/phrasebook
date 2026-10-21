<?php

namespace Ernestdefoe\Phrasebook\Net;

/**
 * Every call to an AI or translation provider goes through here.
 *
 * Before connecting: http(s) only, no credentials or backslashes in the
 * authority, hosts written as numbers only in canonical form, every address
 * the name resolves to classified (Address), and the connection pinned to one
 * of them with CURLOPT_RESOLVE so DNS can't answer differently a moment
 * later. Plain http is accepted only for a local address, so an API key never
 * crosses the internet unencrypted. No redirects, no proxies, capped size,
 * timeouts. Errors carry a reason code, never the URL, a header or the key.
 */
class Http
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var (callable(string): list<string>)|null */
    private $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver for tests
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed>|null $json body, sent as JSON; null for GET
     */
    public function request(string $method, string $url, array $headers, ?array $json, bool $allowLocal, int $timeout = 120): Response
    {
        $target = $this->target($url, $allowLocal);

        return $this->send($method, $target, $headers, $json === null ? null : (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
    }

    /**
     * Checks a URL without calling it: what the settings page uses to say a
     * base URL will be refused before the admin saves it.
     *
     * @return array{url: string, host: string, port: int, ip: string}
     */
    public function target(string $url, bool $allowLocal): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new Refused('url');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new Refused('url');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new Refused('scheme');
        }

        preg_match('~^[a-z][a-z0-9+.-]*://([^/?#]*)~i', $url, $authority);

        if (isset($parts['user']) || isset($parts['pass']) || ! isset($authority[1]) || strpbrk($authority[1], '@\\') !== false) {
            throw new Refused('credentials');
        }

        $host = strtolower($parts['host']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($port < 1 || $port > 65535) {
            throw new Refused('url');
        }

        if (str_starts_with($host, '[')) {
            $literal = substr($host, 1, -1);
            if (! str_ends_with($host, ']') || filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new Refused('url');
            }
            $ips = [$literal];
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ips = [$host];
        } else {
            $host = rtrim($host, '.');

            if (! self::isHostname($host, $allowLocal)) {
                throw new Refused('url');
            }

            $ips = $this->resolve($host, $allowLocal);
        }

        if ($ips === []) {
            throw new Refused('dns');
        }

        $local = false;

        foreach ($ips as $ip) {
            $kind = Address::classify($ip);

            if ($kind === Address::NEVER || ($kind === Address::LOCAL && ! $allowLocal)) {
                throw new Refused($kind === Address::LOCAL ? 'local_disabled' : 'blocked_address');
            }

            $local = $local || $kind === Address::LOCAL;
        }

        if ($scheme === 'http' && ! $local) {
            throw new Refused('https_required');
        }

        $path = $parts['path'] ?? '';
        $rebuilt = "$scheme://".(str_contains($host, ':') && ! str_starts_with($host, '[') ? "[$host]" : $host).":$port$path".(isset($parts['query']) ? '?'.$parts['query'] : '');

        return ['url' => $rebuilt, 'host' => trim($host, '[]'), 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * @param array{url: string, host: string, port: int, ip: string} $target
     * @param array<string, string> $headers
     */
    protected function send(string $method, array $target, array $headers, ?string $body, int $timeout): Response
    {
        $pin = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
        $lines = ['Accept: application/json', 'User-Agent: Phrasebook (Flarum)'];

        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
        }

        $received = [];
        $data = '';
        $tooLarge = false;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $target['url'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$received): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $received = [];
                } elseif (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$data, &$tooLarge): int {
                if (strlen($data) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $data .= $chunk;

                return strlen($chunk);
            },
        ]);

        // An address literal needs no pin: there is nothing to resolve.
        if (filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
            curl_setopt($curl, CURLOPT_RESOLVE, [$target['host'].':'.$target['port'].':'.$pin]);
        }

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        curl_exec($curl);

        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $connected = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);

        if ($tooLarge) {
            throw new Refused('too_large');
        }

        if ($errno !== 0) {
            throw new Refused($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'connect');
        }

        if ($connected !== '' && @inet_pton($connected) !== @inet_pton($target['ip'])) {
            throw new Refused('blocked_address');
        }

        return new Response($status, $received, $data);
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host, bool $allowLocal): array
    {
        if ($this->resolver) {
            return ($this->resolver)($host);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return $allowLocal ? ['127.0.0.1'] : [];
        }

        // DNS itself: no hosts file and no numeric-host expansion.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        // A Docker service name or a hosts-file entry ("ollama",
        // "host.docker.internal") when the admin has asked for local models.
        if ($ips === [] && $allowLocal) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    private static function isHostname(string $host, bool $allowLocal): bool
    {
        if (strlen($host) > 253) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        // A last label that is all digits would be a number, not a name.
        if (preg_match('/^\d+$/', (string) end($labels))) {
            return false;
        }

        return count($labels) >= 2 || $allowLocal;
    }
}
