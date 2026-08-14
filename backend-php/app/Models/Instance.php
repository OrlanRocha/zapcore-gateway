<?php

namespace App\Models;

use App\Core\App;
use PDO;

class Instance extends Model
{
    protected string $table = 'instances';

    public int $id;
    public int $user_id;
    public string $uuid;
    public string $name;
    public string $provider;
    public string $status;
    public ?string $phone_number;
    public ?string $profile_name;
    public ?string $qr_code;
    public ?string $qr_updated_at;
    public ?string $last_connected_at;
    public ?string $last_disconnected_at;
    public int $active;

    public function findByUuid(string $uuid)
    {
        $stmt = App::$app->db->prepare("SELECT * FROM {$this->table} WHERE uuid = :uuid LIMIT 1");
        $stmt->execute(['uuid' => $uuid]);
        $data = $stmt->fetch();
        if ($data) {
            $this->load($data);
            return $this;
        }
        return null;
    }

    public function findByIdForUser(int|string $id, int $userId): ?self
    {
        $stmt = App::$app->db->prepare("SELECT * FROM {$this->table} WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $data = $stmt->fetch();
        if (!$data) {
            return null;
        }

        $this->load($data);
        return $this;
    }

    public function create(array $data)
    {
        $stmt = App::$app->db->prepare("INSERT INTO {$this->table} (user_id, uuid, name, provider, status) VALUES (:user_id, :uuid, :name, :provider, :status)");
        $stmt->execute([
            'user_id' => $data['user_id'],
            'uuid' => $data['uuid'],
            'name' => $data['name'],
            'provider' => $data['provider'] ?? 'baileys',
            'status' => 'created'
        ]);
        return $this->findById(App::$app->db->pdo->lastInsertId());
    }

    public function update(array $data): void
    {
        if (!$data) {
            return;
        }

        $allowed = [
            'status', 'phone_number', 'profile_name', 'qr_code', 'qr_updated_at',
            'last_connected_at', 'last_disconnected_at', 'active'
        ];
        $sets = [];
        $params = ['id' => $this->id];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = "$key = :$key";
            $params[$key] = $value;
        }

        if (!$sets) {
            return;
        }

        $stmt = App::$app->db->prepare("UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = :id");
        $stmt->execute($params);
        foreach ($params as $key => $value) {
            if ($key !== 'id' && property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public function updateStatus(string $status)
    {
        $stmt = App::$app->db->prepare("UPDATE {$this->table} SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $this->id]);
        $this->status = $status;
    }

    public static function all()
    {
        $stmt = App::$app->db->prepare("
            SELECT i.*,
                   COALESCE(mc.app_messages_count, 0) AS app_messages_count,
                   COALESCE(mc.app_user_count, 0) AS app_user_count,
                   COALESCE(mc.app_group_count, 0) AS app_group_count,
                   COALESCE(mc.app_newsletter_count, 0) AS app_newsletter_count
            FROM instances i
            LEFT JOIN (
                SELECT
                    instance_id,
                    COUNT(*) AS app_messages_count,
                    SUM(chat_type = 'user') AS app_user_count,
                    SUM(chat_type = 'group') AS app_group_count,
                    SUM(chat_type = 'newsletter') AS app_newsletter_count
                FROM messages
                WHERE direction = 'outbound'
                GROUP BY instance_id
            ) mc ON mc.instance_id = i.id
            ORDER BY i.id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function allByUser(int $userId): array
    {
        $stmt = App::$app->db->prepare("
            SELECT i.*,
                   COALESCE(mc.app_messages_count, 0) AS app_messages_count,
                   COALESCE(mc.app_user_count, 0) AS app_user_count,
                   COALESCE(mc.app_group_count, 0) AS app_group_count,
                   COALESCE(mc.app_newsletter_count, 0) AS app_newsletter_count
            FROM instances i
            LEFT JOIN (
                SELECT
                    instance_id,
                    COUNT(*) AS app_messages_count,
                    SUM(chat_type = 'user') AS app_user_count,
                    SUM(chat_type = 'group') AS app_group_count,
                    SUM(chat_type = 'newsletter') AS app_newsletter_count
                FROM messages
                WHERE direction = 'outbound'
                GROUP BY instance_id
            ) mc ON mc.instance_id = i.id
            WHERE i.user_id = :user_id
            ORDER BY i.id DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
