<?php

namespace App\Models;

use App\Core\App;

class Webhook extends Model
{
    protected string $table = 'webhooks';

    public static function all()
    {
        $stmt = App::$app->db->prepare("SELECT * FROM webhooks ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $stmt = App::$app->db->prepare("
            INSERT INTO webhooks (instance_id, name, url, secret, events, active)
            VALUES (:instance_id, :name, :url, :secret, :events, :active)
        ");
        $stmt->execute([
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $data['secret'],
            'events' => json_encode($data['events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'active' => !empty($data['active']) ? 1 : 0
        ]);

        return (int) App::$app->db->pdo->lastInsertId();
    }

    public static function logs(int $limit = 100): array
    {
        $stmt = App::$app->db->prepare("
            SELECT wl.*, w.name AS webhook_name, i.uuid AS instance_uuid
            FROM webhook_logs wl
            JOIN webhooks w ON w.id = wl.webhook_id
            LEFT JOIN instances i ON i.id = wl.instance_id
            ORDER BY wl.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function logsByUser(int $userId, int $limit = 100): array
    {
        $stmt = App::$app->db->prepare("
            SELECT wl.*, w.name AS webhook_name, i.uuid AS instance_uuid
            FROM webhook_logs wl
            JOIN webhooks w ON w.id = wl.webhook_id
            JOIN instances i ON i.id = wl.instance_id
            WHERE i.user_id = :owner_user_id OR EXISTS (
                SELECT 1 FROM instance_shares ish
                WHERE ish.instance_id = i.id AND ish.user_id = :shared_user_id
            )
            ORDER BY wl.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue('owner_user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('shared_user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
