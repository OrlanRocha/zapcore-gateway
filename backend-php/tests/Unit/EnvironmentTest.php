<?php

declare(strict_types=1);

use App\Core\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zapcore-secrets-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        if (is_dir($this->root)) rmdir($this->root);
    }

    public function test_reads_direct_or_file_secret_without_exposing_it(): void
    {
        self::assertSame('direct-secret-value-1234567890', Environment::secret('DB_PASSWORD', ['DB_PASSWORD' => 'direct-secret-value-1234567890']));

        $file = $this->root . '/db-password';
        file_put_contents($file, "file-secret-value-1234567890\n");
        self::assertSame('file-secret-value-1234567890', Environment::secret('DB_PASSWORD', ['DB_PASSWORD_FILE' => $file]));
    }

    public function test_rejects_missing_conflicting_empty_and_non_file_secrets(): void
    {
        $empty = $this->root . '/empty';
        file_put_contents($empty, " \n");
        $directory = $this->root . '/directory';
        mkdir($directory, 0700);

        foreach ([
            [],
            ['DB_PASSWORD' => 'direct', 'DB_PASSWORD_FILE' => $empty],
            ['DB_PASSWORD_FILE' => $this->root . '/missing'],
            ['DB_PASSWORD_FILE' => $empty],
            ['DB_PASSWORD_FILE' => $directory],
            ['DB_PASSWORD' => 'short'],
            ['DB_PASSWORD' => 'zapcore_pass'],
        ] as $environment) {
            try {
                Environment::secret('DB_PASSWORD', $environment);
                self::fail('Expected secret validation failure');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('DB_PASSWORD', $exception->getMessage());
                self::assertStringNotContainsString('direct', $exception->getMessage());
                self::assertStringNotContainsString('zapcore_pass', $exception->getMessage());
            }
        }
    }

    public function test_rejects_symbolic_link_secret_files(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows test environments do not reliably permit symlink creation.');
        }

        $target = $this->root . '/target';
        $link = $this->root . '/link';
        file_put_contents($target, "file-secret-value-1234567890\n");
        symlink($target, $link);

        $this->expectException(RuntimeException::class);
        Environment::secret('DB_PASSWORD', ['DB_PASSWORD_FILE' => $link]);
    }

    public function test_requires_non_secret_settings_without_unsafe_defaults(): void
    {
        self::assertSame('mysql', Environment::required('DB_HOST', ['DB_HOST' => 'mysql']));

        $this->expectException(RuntimeException::class);
        Environment::required('DB_HOST', []);
    }

    public function test_web_entrypoint_allows_container_environment_without_dotenv_file(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertIsString($entrypoint);
        self::assertStringContainsString('->safeLoad()', $entrypoint);
        self::assertStringNotContainsString('->load()', $entrypoint);
    }
}
