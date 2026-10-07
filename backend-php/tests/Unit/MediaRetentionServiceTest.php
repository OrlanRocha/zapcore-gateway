<?php

use App\Services\MediaRetentionService;
use App\Models\StorageSetting;
use PHPUnit\Framework\TestCase;

final class MediaRetentionServiceTest extends TestCase
{
    public function test_percent_mode_cleans_from_threshold_to_target(): void
    {
        $this->assertSame(100, MediaRetentionService::requiredBytes([
            'mode' => 'percent', 'percent_threshold' => 80, 'percent_target' => 70,
        ], ['partition_total_bytes' => 1000, 'partition_used_bytes' => 800, 'partition_used_percent' => 80.0, 'media_bytes' => 500]));
    }

    public function test_absolute_mode_has_no_percentage_target(): void
    {
        $this->assertSame(200, MediaRetentionService::requiredBytes([
            'mode' => 'absolute', 'absolute_bytes' => 300,
        ], ['partition_total_bytes' => 1000, 'partition_used_bytes' => 900, 'partition_used_percent' => 90.0, 'media_bytes' => 500]));
        $this->assertSame(0, MediaRetentionService::requiredBytes([
            'mode' => 'absolute', 'absolute_bytes' => 600,
        ], ['partition_total_bytes' => 1000, 'partition_used_bytes' => 900, 'partition_used_percent' => 90.0, 'media_bytes' => 500]));

        $validated = StorageSetting::validate(['mode' => 'absolute', 'absolute_bytes' => 1048576, 'interval_minutes' => 60]);
        $this->assertNull($validated['percent_threshold']);
        $this->assertNull($validated['percent_target']);
    }

    public function test_selects_oldest_candidates_only_until_required_bytes(): void
    {
        $rows = [
            ['id' => 1, 'file_size' => 40], ['id' => 2, 'file_size' => 35], ['id' => 3, 'file_size' => 50],
        ];
        $selected = MediaRetentionService::selectCandidates($rows, 70);
        $this->assertSame([1, 2], array_column($selected, 'id'));
    }

    public function test_rejects_traversal_and_symlink_escape(): void
    {
        $root = sys_get_temp_dir() . '/zapcore-retention-' . bin2hex(random_bytes(4));
        mkdir($root, 0770, true);
        file_put_contents($root . '/inside.bin', 'ok');
        try {
            $this->assertSame(realpath($root . '/inside.bin'), MediaRetentionService::resolveDeletionPath($root, 'inside.bin'));
            $this->assertNull(MediaRetentionService::resolveDeletionPath($root, '../outside.bin'));
            $this->assertNull(MediaRetentionService::resolveDeletionPath($root, $root . '/inside.bin'));
        } finally {
            @unlink($root . '/inside.bin'); @rmdir($root);
        }
    }
}
