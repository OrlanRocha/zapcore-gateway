<?php

namespace App\Models;

use App\Core\App;

class Message extends Model
{
    protected string $table = 'messages';

    public int $id;
    public int $instance_id;
    public ?int $chat_id;
    public string $whatsapp_message_id;
    public string $direction;
    public string $from_jid;
    public string $to_jid;
    public string $chat_type = 'user';
    public string $message_type;
    public ?string $body;
    public ?string $raw_json;
    public string $status;

    public function create(array $data)
    {
        $stmt = App::$app->db->prepare("
            INSERT INTO {$this->table} 
            (instance_id, chat_id, whatsapp_message_id, direction, from_jid, to_jid, chat_type, message_type, body, raw_json, status, sent_at, received_at) 
            VALUES (:instance_id, :chat_id, :whatsapp_message_id, :direction, :from_jid, :to_jid, :chat_type, :message_type, :body, :raw_json, :status, :sent_at, :received_at)
        ");
        $stmt->execute([
            'instance_id' => $data['instance_id'],
            'chat_id' => $data['chat_id'] ?? null,
            'whatsapp_message_id' => $data['whatsapp_message_id'],
            'direction' => $data['direction'],
            'from_jid' => $data['from_jid'],
            'to_jid' => $data['to_jid'],
            'chat_type' => $data['chat_type'] ?? 'user',
            'message_type' => $data['message_type'],
            'body' => $data['body'],
            'raw_json' => isset($data['raw_json']) ? json_encode($data['raw_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'status' => $data['status'],
            'sent_at' => $data['sent_at'] ?? null,
            'received_at' => $data['received_at'] ?? null
        ]);
        return $this->findById(App::$app->db->pdo->lastInsertId());
    }

    public static function findByWhatsappId(int $instanceId, string $whatsappMessageId): ?array
    {
        $stmt = App::$app->db->prepare("SELECT * FROM messages WHERE instance_id = :instance_id AND whatsapp_message_id = :message_id LIMIT 1");
        $stmt->execute([
            'instance_id' => $instanceId,
            'message_id' => $whatsappMessageId
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function all()
    {
        $stmt = App::$app->db->prepare("
            SELECT m.*, i.uuid AS instance_uuid, i.name AS instance_name
            FROM messages m
            JOIN instances i ON i.id = m.instance_id
            ORDER BY m.id DESC
            LIMIT 100
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
