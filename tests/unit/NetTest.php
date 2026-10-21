<?php

namespace Ernestdefoe\Phrasebook\Tests\unit;

use Ernestdefoe\Phrasebook\Net\Address;
use Ernestdefoe\Phrasebook\Net\Refused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NetTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function addresses(): array
    {
        return [
            ['93.184.215.14', Address::PUBLIC],
            ['2606:4700::6810:84e5', Address::PUBLIC],
            ['127.0.0.1', Address::LOCAL],
            ['::1', Address::LOCAL],
            ['10.1.2.3', Address::LOCAL],
            ['192.168.1.20', Address::LOCAL],
            ['172.20.0.5', Address::LOCAL],
            ['100.101.102.103', Address::LOCAL],
            ['fd12:3456::1', Address::LOCAL],
            ['169.254.169.254', Address::NEVER],
            ['fd00:ec2::254', Address::NEVER],
            ['100.100.100.200', Address::NEVER],
            ['0.0.0.0', Address::NEVER],
            ['fe80::1', Address::NEVER],
            ['::ffff:127.0.0.1', Address::NEVER],
            ['::ffff:169.254.169.254', Address::NEVER],
            ['64:ff9b::a9fe:a9fe', Address::NEVER],
            ['2002:a9fe:a9fe::1', Address::NEVER],
            ['224.0.0.1', Address::NEVER],
            ['0177.0.0.1', Address::NEVER],
            ['2130706433', Address::NEVER],
        ];
    }

    #[DataProvider('addresses')]
    public function test_classifies_addresses(string $ip, string $kind): void
    {
        $this->assertSame($kind, Address::classify($ip));
    }

    /** @return list<array{0: string, 1: bool, 2: string|null, 3?: array<string, list<string>>}> */
    public static function urls(): array
    {
        return [
            'public https' => ['https://api.example.com/v1', false, null],
            'public http' => ['http://api.example.com/v1', false, 'https_required'],
            'localhost off' => ['http://localhost:11434/v1', false, 'url'],
            'localhost on' => ['http://localhost:11434/v1', true, null],
            'loopback off' => ['http://127.0.0.1:11434/v1', false, 'local_disabled'],
            'loopback on' => ['http://127.0.0.1:11434/v1', true, null],
            'ipv6 loopback on' => ['http://[::1]:1234/v1', true, null],
            'metadata' => ['http://169.254.169.254/latest', true, 'blocked_address'],
            'metadata v6' => ['http://[fd00:ec2::254]/latest', true, 'blocked_address'],
            'mapped' => ['http://[::ffff:169.254.169.254]/', true, 'blocked_address'],
            'octal' => ['http://0177.0.0.1/', true, 'url'],
            'decimal' => ['http://2130706433/', true, 'url'],
            'hex' => ['http://0x7f.1/', true, 'url'],
            'credentials' => ['https://user:pass@api.example.com/v1', false, 'credentials'],
            'backslash' => ['https://evil.example\\@api.example.com/', false, 'credentials'],
            'file' => ['file:///etc/passwd', true, 'url'],
            'gopher' => ['gopher://api.example.com/', true, 'scheme'],
            'control' => ["https://api.example.com/\x01", false, 'url'],
            'rebinding' => ['https://rebind.example/v1', false, 'local_disabled', ['rebind.example' => ['93.184.215.14', '127.0.0.1']]],
            'dns to metadata' => ['https://meta.example/', true, 'blocked_address', ['meta.example' => ['169.254.169.254']]],
            'docker name on' => ['http://ollama:11434/v1', true, null, ['ollama' => ['172.18.0.4']]],
            'docker name off' => ['http://ollama:11434/v1', false, 'url', ['ollama' => ['172.18.0.4']]],
            'local name over http to public ip' => ['http://box:8080/v1', true, 'https_required', ['box' => ['93.184.215.14']]],
        ];
    }

    /**
     * @param array<string, list<string>> $dns
     */
    #[DataProvider('urls')]
    public function test_guards_every_url(string $url, bool $allowLocal, ?string $refusal, array $dns = []): void
    {
        $http = new FakeHttp($dns + ['localhost' => ['127.0.0.1']]);

        try {
            $target = $http->target($url, $allowLocal);
            $this->assertNull($refusal, 'allowed: '.$url);
            $this->assertNotEmpty($target['ip']);
        } catch (Refused $e) {
            $this->assertSame($refusal, $e->reason, $url);
        }
    }

    public function test_the_connection_is_pinned_to_the_checked_address(): void
    {
        $http = new FakeHttp(['api.example.com' => ['93.184.215.14']]);
        $http->answer(200, ['ok' => true])->request('GET', 'https://api.example.com/v1/models', [], null, false);

        $this->assertSame('93.184.215.14', $http->sent[0]['target']['ip']);
        $this->assertSame('api.example.com', $http->sent[0]['target']['host']);
        $this->assertSame('https://api.example.com:443/v1/models', $http->sent[0]['target']['url']);
    }
}
