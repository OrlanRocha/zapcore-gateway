<?php

namespace App\Services;

use App\Services\Network\PublicUrlException;
use App\Services\Network\SafeHttpClient;

final class RemoteMediaDownloadService
{
    public const MAX_BYTES = 16777216;

    public function __construct(
        private readonly SafeHttpClient $http = new SafeHttpClient(),
        private readonly MediaUploadService $storage = new MediaUploadService(),
    ) {
    }

    public function download(
        string $url,
        int $instanceId,
        string $mediaType,
        ?string $fileName = null,
    ): StoredMedia {
        $temporary = $this->storage->createTemporaryFile();
        $stream = fopen($temporary, 'w+b');
        if ($stream === false) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to open temporary media file');
        }

        try {
            try {
                $response = $this->http->download($url, $stream, self::MAX_BYTES, 3);
            } catch (PublicUrlException $e) {
                throw new MediaUploadException($e->getMessage(), 422);
            }
            fclose($stream);
            $stream = null;

            if ($response->status < 200 || $response->status >= 300) {
                throw new MediaUploadException("Remote media request failed with HTTP {$response->status}");
            }

            $name = $fileName ?: basename((string) (parse_url($url, PHP_URL_PATH) ?: 'media'));
            return $this->storage->storeLocalFile($temporary, $instanceId, $mediaType, $name, self::MAX_BYTES);
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    public function downloadAndUse(
        string $url,
        int $instanceId,
        string $mediaType,
        ?string $fileName,
        callable $consumer,
    ): mixed {
        $stored = $this->download($url, $instanceId, $mediaType, $fileName);
        try {
            return $consumer($stored);
        } catch (\Throwable $e) {
            $this->storage->remove($stored);
            throw $e;
        }
    }
}
