<?php

declare(strict_types=1);

use App\Services\MediaUploadException;
use App\Services\MediaUploadService;
use App\Services\Network\HttpResponse;
use App\Services\Network\HttpTransport;
use App\Services\Network\PublicUrlPolicy;
use App\Services\Network\ResolvedUrl;
use App\Services\Network\SafeHttpClient;
use App\Services\RemoteMediaDownloadService;
use App\Services\StoredMedia;
use PHPUnit\Framework\TestCase;

final class BodyHttpTransport implements HttpTransport
{
    public function __construct(private readonly string $body, private readonly int $status = 200)
    {
    }

    public function send(ResolvedUrl $destination, string $method, array $headers, ?string $body, $target, int $maxBytes): HttpResponse
    {
        if (strlen($this->body) > $maxBytes) {
            throw new \App\Services\Network\PublicUrlException('The remote response exceeds the allowed size');
        }
        fwrite($target, $this->body);
        return new HttpResponse($this->status, ['content-type' => ['application/octet-stream']], strlen($this->body));
    }
}

final class RemoteMediaDownloadServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zapcore-remote-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testDownloadsValidMediaAndPromotesItToOutgoingStorage(): void
    {
        $service = $this->service($this->png());

        $stored = $service->download(
            'https://files.example.test/photo.png',
            7,
            'image',
            null,
        );

        self::assertSame('image/png', $stored->mimeType);
        self::assertSame(strlen($this->png()), $stored->size);
        self::assertStringStartsWith('outgoing/7/', $stored->relativePath);
        self::assertFileExists($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored->relativePath));
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.tmp' . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testRejectsMimeMismatchAndRemovesTemporaryFile(): void
    {
        $service = $this->service('plain text');

        try {
            $service->download('https://files.example.test/not-image.txt', 7, 'image');
            self::fail('Expected MIME mismatch');
        } catch (MediaUploadException $e) {
            self::assertStringContainsString('does not match', $e->getMessage());
        }

        self::assertSame([], $this->filesUnder($this->root));
    }

    public function testRemovesPromotedFileWhenQueueConsumerFails(): void
    {
        $service = $this->service($this->png());

        try {
            $service->downloadAndUse(
                'https://files.example.test/photo.png',
                9,
                'image',
                null,
                static function (StoredMedia $media): never {
                    throw new RuntimeException('queue failed');
                },
            );
            self::fail('Expected queue failure');
        } catch (RuntimeException $e) {
            self::assertSame('queue failed', $e->getMessage());
        }

        self::assertSame([], $this->filesUnder($this->root));
    }

    public function testRejectsNonSuccessfulHttpStatusWithoutLeavingFiles(): void
    {
        $service = $this->service('not found', 404);

        $this->expectException(MediaUploadException::class);
        try {
            $service->download('https://files.example.test/missing', 3, 'document');
        } finally {
            self::assertSame([], $this->filesUnder($this->root));
        }
    }

    private function service(string $body, int $status = 200): RemoteMediaDownloadService
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => ['93.184.216.34']);
        $client = new SafeHttpClient($policy, new BodyHttpTransport($body, $status));
        return new RemoteMediaDownloadService($client, new MediaUploadService($this->root));
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    }

    private function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) return [];
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) $files[] = $file->getPathname();
        }
        return $files;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
