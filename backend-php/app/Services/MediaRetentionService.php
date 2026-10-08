<?php

namespace App\Services;

use App\Core\App;
use App\Models\StorageCleanupRun;
use App\Models\StorageSetting;

final class MediaRetentionService
{
    private const LOCK_NAME = 'zapcore_media_retention';

    public function __construct(private readonly ?StorageMetricsService $metrics = null) {}

    public static function requiredBytes(array $settings, array $metrics): int
    {
        if (($settings['mode'] ?? 'percent') === 'absolute') {
            return max(0, (int) $metrics['media_bytes'] - (int) ($settings['absolute_bytes'] ?? 0));
        }
        if ((float) $metrics['partition_used_percent'] < (int) ($settings['percent_threshold'] ?? 80)) return 0;
        $targetUsed = (int) floor((int) $metrics['partition_total_bytes'] * ((int) ($settings['percent_target'] ?? 75) / 100));
        return max(0, (int) $metrics['partition_used_bytes'] - $targetUsed);
    }

    public static function selectCandidates(array $rows, int $requiredBytes): array
    {
        $selected = []; $bytes = 0;
        foreach ($rows as $row) {
            if ($bytes >= $requiredBytes) break;
            $selected[] = $row;
            $bytes += max(0, (int) ($row['file_size'] ?? 0));
        }
        return $selected;
    }

    public static function shouldAdvanceSchedule(bool $dryRun): bool
    {
        return !$dryRun;
    }

    public static function resolveDeletionPath(string $root, string $relativePath): ?string
    {
        if ($relativePath === '' || preg_match('#^(?:[A-Za-z]:|[/\\\\])#', $relativePath) || in_array('..', preg_split('#[/\\\\]+#', $relativePath), true)) return null;
        $realRoot = realpath($root); $realFile = $realRoot ? realpath($realRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)) : false;
        if (!$realRoot || !$realFile || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR) || !is_file($realFile)) return null;
        return $realFile;
    }

    public function run(string $trigger = 'cron', bool $dryRun = false, ?int $userId = null): array
    {
        $pdo = App::$app->db->pdo;
        $lock = $pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            $settings = StorageSetting::current();
            $snapshot = ($this->metrics ?? new StorageMetricsService())->snapshot();
            $runId = StorageCleanupRun::start([
                'triggered_by' => $dryRun ? 'dry_run' : $trigger, 'mode' => $settings['mode'],
                'threshold_value' => $settings['mode'] === 'percent' ? (int) $settings['percent_threshold'] : (int) $settings['absolute_bytes'],
                'target_value' => $settings['mode'] === 'percent' ? (int) $settings['percent_target'] : null,
                'bytes_before' => $snapshot['media_bytes'], 'files_before' => $snapshot['media_files'], 'user_id' => $userId,
            ]);
            StorageCleanupRun::finish($runId, [
                'bytes_after'=>$snapshot['media_bytes'], 'files_after'=>$snapshot['media_files'],
                'bytes_selected'=>0, 'files_selected'=>0, 'bytes_deleted'=>0, 'files_deleted'=>0,
                'status'=>'skipped', 'error_summary'=>'Another cleanup is already running',
            ]);
            return ['status' => 'skipped', 'reason' => 'cleanup_locked'];
        }

        $runId = null;
        try {
            $settings = StorageSetting::current();
            $metricsService = $this->metrics ?? new StorageMetricsService();
            $before = $metricsService->snapshot();
            $required = self::requiredBytes($settings, $before);
            $runId = StorageCleanupRun::start([
                'triggered_by' => $dryRun ? 'dry_run' : $trigger, 'mode' => $settings['mode'],
                'threshold_value' => $settings['mode'] === 'percent' ? (int) $settings['percent_threshold'] : (int) $settings['absolute_bytes'],
                'target_value' => $settings['mode'] === 'percent' ? (int) $settings['percent_target'] : null,
                'bytes_before' => $before['media_bytes'], 'files_before' => $before['media_files'], 'user_id' => $userId,
            ]);

            $stmt = $pdo->query("SELECT mm.id, mm.file_path, mm.file_size, mm.created_at FROM message_media mm WHERE mm.removed_at IS NULL AND NOT EXISTS (SELECT 1 FROM send_queue sq WHERE sq.message_id=mm.message_id AND sq.status IN ('pending','processing')) ORDER BY mm.created_at ASC, mm.id ASC LIMIT 5000");
            $selected = $required > 0 ? self::selectCandidates($stmt->fetchAll(), $required) : [];
            $selectedBytes = array_sum(array_map(fn($row) => max(0, (int) $row['file_size']), $selected));
            $deletedBytes = 0; $deletedFiles = 0; $errors = [];

            if (!$dryRun) foreach ($selected as $row) {
                $path = self::resolveDeletionPath($before['storage_root'], (string) $row['file_path']);
                if (!$path) {
                    $candidate = $before['storage_root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['file_path']);
                    if (!file_exists($candidate)) {
                        $pdo->prepare("UPDATE message_media SET removed_at=NOW(), removal_reason='retention_missing' WHERE id=:id AND removed_at IS NULL")->execute(['id' => $row['id']]);
                    } else {
                        $errors[] = 'Unsafe media path for id ' . $row['id'];
                    }
                    continue;
                }
                $size = filesize($path) ?: (int) $row['file_size'];
                if (!@unlink($path)) { $errors[] = 'Unable to delete media id ' . $row['id']; continue; }
                $pdo->prepare("UPDATE message_media SET removed_at=NOW(), removal_reason='retention_limit' WHERE id=:id AND removed_at IS NULL")->execute(['id' => $row['id']]);
                $deletedBytes += $size; $deletedFiles++;
            }

            $after = $dryRun ? $before : $metricsService->snapshot();
            $status = $errors || (!$dryRun && $required > 0 && $deletedBytes < $required) ? 'partial' : 'completed';
            StorageCleanupRun::finish($runId, [
                'bytes_after' => $after['media_bytes'], 'files_after' => $after['media_files'],
                'bytes_selected' => $selectedBytes, 'files_selected' => count($selected),
                'bytes_deleted' => $deletedBytes, 'files_deleted' => $deletedFiles,
                'status' => $status, 'error_summary' => $errors ? implode('; ', $errors) : null,
            ]);
            if (self::shouldAdvanceSchedule($dryRun)) {
                $pdo->prepare('UPDATE storage_settings SET last_run_at=NOW(), last_run_status=:status WHERE id=1')->execute(['status' => $status]);
            }
            return compact('status', 'required', 'selectedBytes', 'deletedBytes', 'deletedFiles') + ['files_selected' => count($selected), 'dry_run' => $dryRun];
        } catch (\Throwable $e) {
            if ($runId) StorageCleanupRun::finish($runId, ['bytes_after'=>0,'files_after'=>0,'bytes_selected'=>0,'files_selected'=>0,'bytes_deleted'=>0,'files_deleted'=>0,'status'=>'failed','error_summary'=>$e->getMessage()]);
            throw $e;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }
}
