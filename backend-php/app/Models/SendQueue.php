<?php

namespace App\Models;

use App\Core\App;

class SendQueue extends Model
{
    protected string $table = 'send_queue';

    public int $id;
    public int $instance_id;
    public int $message_id;
    public string $to_jid;
    public string $payload_json;
    public string $status;
    public int $attempts;
    public int $max_attempts;
    public ?string $scheduled_at;
    public ?string $processed_at;
    public ?string $error_message;

    public function create(array $data)
    {
        $payload = $data['payload_json'] ?? json_encode($data['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = App::$app->db->prepare("
            INSERT INTO {$this->table} 
            (instance_id, message_id, to_jid, payload_json, status, scheduled_at) 
            VALUES (:instance_id, :message_id, :to_jid, :payload_json, 'pending', :scheduled_at)
        ");
        $stmt->execute([
            'instance_id' => $data['instance_id'],
            'message_id' => $data['message_id'],
            'to_jid' => $data['to_jid'],
            'payload_json' => $payload,
            'scheduled_at' => $data['scheduled_at'] ?? null
        ]);
        return $this->findById(App::$app->db->pdo->lastInsertId());
    }
}
