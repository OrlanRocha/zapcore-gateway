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
        $result = (new WebhookDeliveryService())->deliver($webhook, $instance, $eventName, $data);

        $stmt = App::$app->db->prepare("
            INSERT INTO webhook_logs (webhook_id, instance_id, event_name, payload_json, response_status, response_body, success, error_message)
            VALUES (:webhook_id, :instance_id, :event_name, :payload_json, :response_status, :response_body, :success, :error_message)
        ");
        $stmt->execute([
            'webhook_id' => $webhook['id'],
            'instance_id' => $instance->id,
            'event_name' => $eventName,
            'payload_json' => $result->payload,
            'response_status' => $result->status,
            'response_body' => $result->responseBody,
            'success' => $result->success ? 1 : 0,
            'error_message' => $result->error,
        ]);

        App::$app->db->prepare('DELETE FROM webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)')->execute();
        App::$app->db->prepare("
            DELETE FROM webhook_logs
            WHERE webhook_id = :webhook_id
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id FROM webhook_logs
                      WHERE webhook_id = :keep_webhook_id
                      ORDER BY id DESC LIMIT 1000
                  ) retained
              )
        ")->execute([
            'webhook_id' => $webhook['id'],
            'keep_webhook_id' => $webhook['id'],
        ]);
    }
}
