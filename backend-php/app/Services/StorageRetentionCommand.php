<?php

namespace App\Services;

final class StorageRetentionCommand
{
    public static function exitCode(array $result): int
    {
        return match ($result['status'] ?? 'failed') {
            'completed', 'partial', 'noop' => 0,
            'skipped' => 2,
            default => 1,
        };
    }
}
