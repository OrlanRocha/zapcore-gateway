<?php

use App\Services\StorageMetricsService;
use PHPUnit\Framework\TestCase;

final class StorageMetricsServiceTest extends TestCase
{
    public function test_snapshot_reports_partition_and_media_usage(): void
    {
        $root = sys_get_temp_dir() . '/zapcore-metrics-' . bin2hex(random_bytes(4));
        mkdir($root, 0770, true);
        file_put_contents($root . '/one.bin', str_repeat('x', 10));
        mkdir($root . '/nested');
        file_put_contents($root . '/nested/two.bin', str_repeat('y', 15));

        try {
            $service = new StorageMetricsService($root, fn() => 1000, fn() => 250);
            $snapshot = $service->snapshot();
            $this->assertSame(1000, $snapshot['partition_total_bytes']);
            $this->assertSame(750, $snapshot['partition_used_bytes']);
            $this->assertSame(75.0, $snapshot['partition_used_percent']);
            $this->assertSame(25, $snapshot['media_bytes']);
            $this->assertSame(2, $snapshot['media_files']);
        } finally {
            @unlink($root . '/nested/two.bin'); @rmdir($root . '/nested'); @unlink($root . '/one.bin'); @rmdir($root);
        }
    }
}
