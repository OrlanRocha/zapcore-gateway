<?php

declare(strict_types=1);

namespace App\Core;

final class Environment
{
    private const UNSAFE_VALUES = [
        'zapcore_pass',
        'root_pass',
        'change_worker_secret',
        'change_internal_secret',
        'change_me_32_chars_minimum_key_123',
    ];

    public static function required(string $name, ?array $environment = null): string
    {
        $value = trim(self::value($name, $environment));
        if ($value === '') {
            throw new \RuntimeException("Missing required configuration: {$name}");
        }

        return $value;
    }

    public static function secret(string $name, ?array $environment = null): string
    {
        $environment ??= self::processEnvironment();
        $direct = trim((string) ($environment[$name] ?? ''));
        $fileName = $name . '_FILE';
        $file = trim((string) ($environment[$fileName] ?? ''));

        if ($direct !== '' && $file !== '') {
            throw new \RuntimeException("Conflicting secret configuration: {$name}");
        }

        if ($file !== '') {
            if (!is_file($file) || !is_readable($file)) {
                throw new \RuntimeException("Unreadable secret file for {$name}");
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException("Unreadable secret file for {$name}");
            }
            $direct = trim($contents);
        }

        if ($direct === '') {
            throw new \RuntimeException("Missing or empty secret: {$name}");
        }
        if (strlen($direct) < 24) {
            throw new \RuntimeException("Secret is too short: {$name}");
        }
        if (in_array(strtolower($direct), self::UNSAFE_VALUES, true) || str_starts_with(strtolower($direct), 'replace_')) {
            throw new \RuntimeException("Unsafe placeholder secret: {$name}");
        }

        return $direct;
    }

    public static function hydrateSecrets(array $names): void
    {
        foreach ($names as $name) {
            $_ENV[$name] = self::secret($name);
        }
    }

    private static function value(string $name, ?array $environment): string
    {
        if ($environment !== null) return (string) ($environment[$name] ?? '');
        return (string) ($_ENV[$name] ?? getenv($name) ?: '');
    }

    private static function processEnvironment(): array
    {
        $environment = $_ENV;
        foreach (getenv() ?: [] as $name => $value) {
            if (!array_key_exists($name, $environment)) $environment[$name] = $value;
        }
        return $environment;
    }
}
