<?php

namespace App\Models;

use App\Core\App;

final class StorageCleanupRun extends Model
{
    protected string $table = 'storage_cleanup_runs';

    public static function start(array $data): int
    {
        App::$app->db->prepare('INSERT INTO storage_cleanup_runs (triggered_by, mode, threshold_value, target_value, bytes_before, files_before, status, user_id) VALUES (:triggered_by,:mode,:threshold_value,:target_value,:bytes_before,:files_before,\'skipped\',:user_id)')->execute($data);
        return (int) App::$app->db->pdo->lastInsertId();
    }

    public static function finish(int $id, array $data): void
    {
        App::$app->db->prepare('UPDATE storage_cleanup_runs SET bytes_after=:bytes_after, files_after=:files_after, bytes_selected=:bytes_selected, files_selected=:files_selected, bytes_deleted=:bytes_deleted, files_deleted=:files_deleted, status=:status, error_summary=:error_summary, finished_at=NOW() WHERE id=:id')->execute($data + ['id' => $id]);
    }

    public static function recent(int $limit = 20): array
    {
        $limit = min(max($limit, 1), 100);
        return App::$app->db->pdo->query("SELECT * FROM storage_cleanup_runs ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }
}
