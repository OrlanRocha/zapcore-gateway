<?php

namespace App\Models;

use App\Core\App;

class Log extends Model
{
    public static function createConnectionLog(int $instance_id, string $event_type, string $description = '', $raw_json = null)
    {
        $stmt = App::$app->db->prepare("
            INSERT INTO connection_logs (instance_id, event_type, description, raw_json) 
            VALUES (:instance_id, :event_type, :description, :raw_json)
        ");
        $stmt->execute([
            'instance_id' => $instance_id,
            'event_type' => $event_type,
            'description' => $description,
            'raw_json' => $raw_json ? json_encode($raw_json) : null
        ]);
    }
}
