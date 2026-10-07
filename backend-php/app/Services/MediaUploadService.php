<?php

namespace App\Services;

final class MediaUploadService
{
    public const MAX_BYTES = 268435456;

    private const MIME_BY_TYPE = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'video' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/aac'],
        'document' => [
            'application/pdf', 'application/zip', 'application/json', 'application/xml',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'text/html', 'text/xml',
        ],
    ];

    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/aac' => 'aac',
        'application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/json' => 'json',
        'application/xml' => 'xml', 'text/plain' => 'txt', 'text/csv' => 'csv', 'text/html' => 'html', 'text/xml' => 'xml',
        'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    private \Closure $uploadedFileCheck;
    private \Closure $moveFile;

    public function __construct(
        private readonly ?string $storageRoot = null,
        ?callable $uploadedFileCheck = null,
        ?callable $moveFile = null,
    ) {
        $this->uploadedFileCheck = \Closure::fromCallable($uploadedFileCheck ?? 'is_uploaded_file');
        $this->moveFile = \Closure::fromCallable($moveFile ?? 'move_uploaded_file');
    }

    public function store(array $upload, int $instanceId, string $mediaType): StoredMedia
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new MediaUploadException('Media file is too large; maximum is 256 MB', 413);
        }
        if ($error !== UPLOAD_ERR_OK) throw new MediaUploadException('Invalid or incomplete media upload');

        $tmp = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($tmp === '' || !($this->uploadedFileCheck)($tmp) || !is_file($tmp) || $size <= 0) {
            throw new MediaUploadException('Invalid or empty media upload');
        }
        if ($size > self::MAX_BYTES) throw new MediaUploadException('Media file is too large; maximum is 256 MB', 413);

        $mediaType = strtolower(trim($mediaType));
        if (!isset(self::MIME_BY_TYPE[$mediaType])) throw new MediaUploadException('Invalid media_type');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        if (!in_array($mime, self::MIME_BY_TYPE[$mediaType], true)) {
            throw new MediaUploadException("Uploaded file type does not match {$mediaType}");
        }

        $root = $this->root();
        $relativeDir = 'outgoing/' . max(1, $instanceId) . '/' . date('Y-m');
        $targetDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Unable to create media storage directory');
        }
        $extension = self::EXTENSION_BY_MIME[$mime] ?? 'bin';
        $relativePath = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!($this->moveFile)($tmp, $target)) throw new \RuntimeException('Unable to store uploaded media');

        return new StoredMedia($relativePath, $this->displayName((string) ($upload['name'] ?? 'media.' . $extension)), $mime, $size);
    }

    public function remove(StoredMedia|string $media): void
    {
        $relative = $media instanceof StoredMedia ? $media->relativePath : $media;
        if ($relative === '' || str_contains($relative, '..') || preg_match('#^[A-Za-z]:|^[/\\\\]#', $relative)) return;
        $root = $this->root();
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($path);
        if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) unlink($real);
    }

    private function root(): string
    {
        $root = $this->storageRoot ?: (getenv('MEDIA_STORAGE_PATH') ?: dirname(__DIR__, 2) . '/storage/media');
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) throw new \RuntimeException('Media storage is unavailable');
        return rtrim((string) realpath($root), DIRECTORY_SEPARATOR);
    }

    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'media';
        return mb_substr($name, 0, 240);
    }
}
