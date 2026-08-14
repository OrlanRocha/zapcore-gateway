<?php

namespace App\Services;

use App\Core\App;
use App\Models\Instance;

class WebhookDispatcher
{
    public static function dispatch(Instance $instance, string $eventName, array $data)
    {
        $stmt = App::$app->db->prepare("
            SELECT * FROM webhooks
            WHERE instance_id = :instance_id
            AND active = 1
        ");
        $stmt->execute(['instance_id' => $instance->id]);
        $webhooks = $stmt->fetchAll();

        foreach ($webhooks as $webhook) {
            $events = json_decode($webhook['events'], true) ?? [];
            if (in_array($eventName, $events) || in_array('*', $events)) {
                self::send($webhook, $instance, $eventName, $data);
            }
        }
    }

    private static function send(array $webhook, Instance $instance, string $eventName, array $data)
    {
        $payload = json_encode([
            'event' => $eventName,
            'instance_uuid' => $instance->uuid,
            'timestamp' => date('c'),
            'data' => $data
        ]);

        $signature = hash_hmac('sha256', $payload, $webhook['secret']);

        $ch = curl_init($webhook['url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-ZapCore-Signature: ' . $signature
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $responseBody = curl_exec($ch);
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = $responseStatus >= 200 && $responseStatus < 300;

        $stmt = App::$app->db->prepare("
            INSERT INTO webhook_logs (webhook_id, instance_id, event_name, payload_json, response_status, response_body, success, error_message)
            VALUES (:webhook_id, :instance_id, :event_name, :payload_json, :response_status, :response_body, :success, :error_message)
        ");
        $stmt->execute([
            'webhook_id' => $webhook['id'],
            'instance_id' => $instance->id,
            'event_name' => $eventName,
            'payload_json' => $payload,
            'response_status' => $responseStatus,
            'response_body' => $responseBody ?: null,
            'success' => $success ? 1 : 0,
            'error_message' => $error ?: null
        ]);
    }
}
