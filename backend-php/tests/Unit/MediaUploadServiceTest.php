<?php

declare(strict_types=1);

use App\Services\MediaUploadException;
use App\Services\MediaUploadService;
use PHPUnit\Framework\TestCase;

final class MediaUploadServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zapcore-upload-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->root);
    }

    public function test_stores_valid_image_under_random_instance_path(): void
    {
        $tmp = $this->file('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $stored = $this->service()->store($this->upload($tmp, 'foto perigosa?.png'), 42, 'image');

        self::assertMatchesRegularExpression('#^outgoing/42/\d{4}-\d{2}/[a-f0-9]{32}\.png$#', $stored->relativePath);
        self::assertSame('foto perigosa_.png', $stored->displayName);
        self::assertSame('image/png', $stored->mimeType);
        self::assertFileExists($this->root . '/' . $stored->relativePath);
    }

    public function test_rejects_mime_mismatch_executable_and_malformed_upload(): void
    {
        $pdf = $this->file('file.pdf', "%PDF-1.4\n%%EOF");
        foreach ([
            fn() => $this->service()->store($this->upload($pdf, 'file.pdf'), 1, 'image'),
            fn() => $this->service()->store($this->upload($this->file('run.exe', "MZ\0\0"), 'run.exe'), 1, 'document'),
            fn() => $this->service()->store(['error' => UPLOAD_ERR_PARTIAL], 1, 'document'),
            fn() => $this->service()->store($this->upload($this->file('empty', ''), 'empty.txt'), 1, 'document'),
        ] as $operation) {
            try { $operation(); self::fail('Expected upload rejection'); } catch (MediaUploadException $e) { self::assertSame(422, $e->httpStatus); }
        }
    }

    public function test_accepts_exact_limit_and_rejects_one_byte_over(): void
    {
        $pdf = $this->file('limit.pdf', "%PDF-1.4\n%%EOF");
        $accepted = $this->upload($pdf, 'limit.pdf'); $accepted['size'] = MediaUploadService::MAX_BYTES;
        self::assertSame(MediaUploadService::MAX_BYTES, $this->service()->store($accepted, 1, 'document')->size);

        $over = $this->upload($this->file('over.pdf', "%PDF-1.4\n%%EOF"), 'over.pdf'); $over['size'] = MediaUploadService::MAX_BYTES + 1;
        try { $this->service()->store($over, 1, 'document'); self::fail('Expected oversized rejection'); }
        catch (MediaUploadException $e) { self::assertSame(413, $e->httpStatus); }
    }

    public function test_remove_deletes_only_an_in_root_stored_file(): void
    {
        $pdf = $this->file('remove.pdf', "%PDF-1.4\n%%EOF");
        $stored = $this->service()->store($this->upload($pdf, 'remove.pdf'), 5, 'document');
        $this->service()->remove($stored);
        self::assertFileDoesNotExist($this->root . '/' . $stored->relativePath);
    }

    private function service(): MediaUploadService
    {
        return new MediaUploadService($this->root, fn(string $path) => is_file($path), function(string $from, string $to) { return rename($from, $to); });
    }

    private function file(string $name, string $content): string
    {
        $path = $this->root . '/' . $name; file_put_contents($path, $content); return $path;
    }

    private function upload(string $tmp, string $name): array
    {
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'type' => 'application/octet-stream'];
    }
}
