<?php

namespace App\Services;

final class StoredMedia
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $displayName,
        public readonly string $mimeType,
        public readonly int $size,
    ) {}
}
