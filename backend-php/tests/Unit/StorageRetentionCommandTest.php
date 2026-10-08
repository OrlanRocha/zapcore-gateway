<?php

use App\Models\StorageSetting;
use App\Services\StorageRetentionCommand;
use PHPUnit\Framework\TestCase;

final class StorageRetentionCommandTest extends TestCase
{
    public function test_disabled_schedule_is_a_no_op(): void
    {
        $this->assertFalse(StorageSetting::isDue([
            'enabled' => 0,
            'interval_minutes' => 5,
            'last_run_at' => null,
        ], new DateTimeImmutable('2026-10-07 12:00:00')));
    }

    public function test_schedule_runs_only_after_interval_has_elapsed(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $settings = ['enabled' => 1, 'interval_minutes' => 60, 'last_run_at' => '2026-10-07 11:30:01'];
        $this->assertFalse(StorageSetting::isDue($settings, $now));

        $settings['last_run_at'] = '2026-10-07 10:59:59';
        $this->assertTrue(StorageSetting::isDue($settings, $now));
    }

    public function test_never_run_enabled_schedule_is_due(): void
    {
        $this->assertTrue(StorageSetting::isDue([
            'enabled' => 1,
            'interval_minutes' => 1440,
            'last_run_at' => null,
        ], new DateTimeImmutable('2026-10-07 12:00:00')));
    }

    public function test_command_maps_cleanup_outcomes_to_stable_exit_codes(): void
    {
        $this->assertSame(0, StorageRetentionCommand::exitCode(['status' => 'completed']));
        $this->assertSame(2, StorageRetentionCommand::exitCode(['status' => 'skipped']));
        $this->assertSame(1, StorageRetentionCommand::exitCode(['status' => 'failed']));
    }
}
