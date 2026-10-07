<?php

namespace App\Services;

final class StorageMetricsService
{
    private \Closure $diskTotal;
    private \Closure $diskFree;

    public function __construct(private readonly ?string $storageRoot = null, ?callable $diskTotal = null, ?callable $diskFree = null)
    {
        $this->diskTotal = \Closure::fromCallable($diskTotal ?? 'disk_total_space');
        $this->diskFree = \Closure::fromCallable($diskFree ?? 'disk_free_space');
    }

    public function snapshot(): array
    {
        $root = $this->root();
        $total = max(0, (int) ($this->diskTotal)($root));
        $free = max(0, (int) ($this->diskFree)($root));
        $used = max(0, $total - $free);
        $mediaBytes = 0;
        $mediaFiles = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            $mediaBytes += $file->getSize();
            $mediaFiles++;
        }

        return [
            'storage_root' => $root,
            'partition_total_bytes' => $total,
            'partition_free_bytes' => $free,
            'partition_used_bytes' => $used,
            'partition_used_percent' => $total > 0 ? round(($used / $total) * 100, 2) : 0.0,
            'media_bytes' => $mediaBytes,
            'media_files' => $mediaFiles,
        ];
    }

    public function root(): string
    {
        $root = $this->storageRoot ?: (getenv('MEDIA_STORAGE_PATH') ?: dirname(__DIR__, 2) . '/storage/media');
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) throw new \RuntimeException('Media storage is unavailable');
        $real = realpath($root);
        if ($real === false) throw new \RuntimeException('Media storage path cannot be resolved');
        return rtrim($real, DIRECTORY_SEPARATOR);
    }
}
