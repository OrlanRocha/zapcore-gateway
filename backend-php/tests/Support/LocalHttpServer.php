<?php

declare(strict_types=1);

final class LocalHttpServer
{
    private $process;
    private string $directory;
    private array $pipes;

    private function __construct(
        public readonly int $port,
        $process,
        string $directory,
        array $pipes,
    ) {
        $this->process = $process;
        $this->directory = $directory;
        $this->pipes = $pipes;
    }

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("Unable to reserve test port: {$error}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zapcore-http-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $router = $directory . DIRECTORY_SEPARATOR . 'router.php';
        file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/large') {
    header('Content-Type: application/octet-stream');
    echo str_repeat('x', 2048);
    return;
}
header('Content-Type: text/plain');
echo 'pinned-ok';
PHP);

        $command = sprintf('"%s" -S 127.0.0.1:%d "%s"', PHP_BINARY, $port, $router);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        if (!is_resource($process)) {
            self::removeDirectory($directory);
            throw new RuntimeException('Unable to start local HTTP test server');
        }

        $deadline = microtime(true) + 5;
        do {
            $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                return new self($port, $process, $directory, $pipes);
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        self::removeDirectory($directory);
        throw new RuntimeException('Local HTTP test server did not become ready');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
        self::removeDirectory($this->directory);
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        for ($attempt = 0; $attempt < 10 && is_dir($directory); $attempt++) {
            if (@rmdir($directory)) {
                break;
            }
            usleep(50000);
        }
    }
}
