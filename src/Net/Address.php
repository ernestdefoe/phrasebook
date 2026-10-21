<?php

namespace Ernestdefoe\Phrasebook\Net;

/**
 * Sorts an IP address into the three kinds Phrasebook cares about:
 *
 *  - public: plainly global unicast, always allowed;
 *  - local: loopback, private networks, unique-local IPv6 and CGNAT (where
 *    Tailscale puts machines), allowed only when the admin has switched on
 *    local models (Ollama, LM Studio on this machine or the LAN);
 *  - never: everything else, above all link-local and cloud metadata
 *    (169.254.169.254, fd00:ec2::254, 100.100.100.200), "this network",
 *    multicast, reserved, documentation, and IPv6 forms that embed an IPv4
 *    address (mapped, NAT64, 6to4, Teredo), which could smuggle any of those.
 *
 * Works on inet_pton()'s packed bytes, so every spelling of an address lands
 * on the same bytes before it is compared; only canonical spellings get that
 * far (filter_var refuses octal, hex and short IPv4 forms).
 */
final class Address
{
    public const PUBLIC = 'public';

    public const LOCAL = 'local';

    public const NEVER = 'never';

    private const NEVER_V4 = [
        '0.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24',
        '192.52.193.0/24', '192.88.99.0/24', '192.175.48.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '100.100.100.200/32',
    ];

    private const LOCAL_V4 = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10'];

    private const NEVER_V6_GLOBAL = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

    public static function classify(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : @inet_pton($ip);

        if ($packed === false) {
            return self::NEVER;
        }

        if (strlen($packed) === 4) {
            foreach (self::NEVER_V4 as $cidr) {
                if (self::inRange($packed, $cidr)) {
                    return self::NEVER;
                }
            }

            foreach (self::LOCAL_V4 as $cidr) {
                if (self::inRange($packed, $cidr)) {
                    return self::LOCAL;
                }
            }

            return self::PUBLIC;
        }

        if ($packed === inet_pton('::1')) {
            return self::LOCAL;
        }

        if (self::inRange($packed, 'fd00:ec2::254/128')) {
            return self::NEVER;
        }

        if (self::inRange($packed, 'fc00::/7')) {
            return self::LOCAL;
        }

        if (! self::inRange($packed, '2000::/3')) {
            return self::NEVER;
        }

        foreach (self::NEVER_V6_GLOBAL as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return self::NEVER;
            }
        }

        return self::PUBLIC;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$base, $bits] = explode('/', $cidr);
        $net = (string) inet_pton($base);

        if (strlen($net) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
