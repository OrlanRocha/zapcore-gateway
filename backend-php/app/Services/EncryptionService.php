<?php

namespace App\Services;

class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $plainText,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Unable to encrypt data');
        }

        return json_encode([
            'cipher' => self::CIPHER,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($encrypted)
        ]);
    }

    public static function decrypt(string $payload): string
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || empty($data['iv']) || empty($data['tag']) || empty($data['data'])) {
            throw new \InvalidArgumentException('Invalid encrypted payload');
        }

        $plain = openssl_decrypt(
            base64_decode($data['data']),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            base64_decode($data['iv']),
            base64_decode($data['tag'])
        );

        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt data');
        }

        return $plain;
    }

    private static function key(): string
    {
        $appKey = $_ENV['APP_KEY'] ?? '';
        if (strlen($appKey) < 32) {
            throw new \RuntimeException('APP_KEY must have at least 32 characters');
        }

        return hash('sha256', $appKey, true);
    }
}
