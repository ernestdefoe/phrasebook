<?php

namespace Ernestdefoe\Phrasebook\Settings;

use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The admin's API keys, one per provider, encrypted at rest.
 *
 * Keys never go into Flarum's settings table: every setting is sent to the
 * admin frontend. They live in their own table, encrypted with a key kept in
 * a file under storage/ (not in the database), so a database dump or backup
 * alone does not give them away. Only the last four characters are ever
 * shown back, to say which key is saved.
 *
 * If the key file is lost (a fresh server with the old database), saved keys
 * read as missing and the admin pastes them again.
 */
class Vault
{
    public const TABLE = 'phrasebook_keys';

    public function __construct(private readonly ConnectionInterface $db, private readonly Paths $paths)
    {
    }

    public function put(string $provider, string $secret): void
    {
        $this->db->table(self::TABLE)->updateOrInsert(
            ['provider' => $provider],
            ['secret' => $this->encrypt($secret), 'hint' => mb_substr($secret, -4), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    public function get(string $provider): ?string
    {
        $row = $this->db->table(self::TABLE)->where('provider', $provider)->first(['secret']);

        return $row ? $this->decrypt((string) $row->secret) : null;
    }

    public function forget(string $provider): void
    {
        $this->db->table(self::TABLE)->where('provider', $provider)->delete();
    }

    /**
     * Which providers have a key, and its last four characters.
     *
     * @return array<string, string>
     */
    public function hints(): array
    {
        return $this->db->table(self::TABLE)->pluck('hint', 'provider')->map(fn ($hint) => (string) $hint)->all();
    }

    private function encrypt(string $plain): string
    {
        $key = $this->key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return 's1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
        }

        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the key.');
        }

        return 'g1:'.base64_encode($iv.$tag.$cipher);
    }

    private function decrypt(string $stored): ?string
    {
        $key = $this->key(false);

        if ($key === null) {
            return null;
        }

        $raw = base64_decode(substr($stored, 3), true);

        if ($raw === false) {
            return null;
        }

        if (str_starts_with($stored, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

            return $plain === false ? null : $plain;
        }

        if (str_starts_with($stored, 'g1:')) {
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

            return $plain === false ? null : $plain;
        }

        return null;
    }

    /**
     * 32 random bytes in storage/phrasebook/secret.key, readable by the web
     * server's user only, made the first time a key is saved.
     */
    private function key(bool $create = true): ?string
    {
        $dir = $this->paths->storage.'/phrasebook';
        $file = $dir.'/secret.key';

        if (is_file($file)) {
            $key = base64_decode((string) file_get_contents($file), true);

            if ($key !== false && strlen($key) === 32) {
                return $key;
            }
        }

        if (! $create) {
            return null;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('storage/phrasebook is not writable.');
        }

        $key = random_bytes(32);
        $tmp = $dir.'/secret.key.'.bin2hex(random_bytes(4));

        $old = umask(0077);
        $written = file_put_contents($tmp, base64_encode($key));
        umask($old);

        if ($written === false || ! @chmod($tmp, 0600)) {
            @unlink($tmp);
            throw new RuntimeException('storage/phrasebook is not writable.');
        }

        // link() refuses to replace an existing file, so when two requests
        // race, the first key wins and the second request reads it back.
        if (! @link($tmp, $file) && ! is_file($file)) {
            @rename($tmp, $file);
        }
        @unlink($tmp);

        return $this->key(false) ?? throw new RuntimeException('storage/phrasebook is not writable.');
    }
}
