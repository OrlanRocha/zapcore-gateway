<?php

namespace App\Services;

use App\Core\App;
use App\Models\Instance;
use App\Models\RecipientConsent;

class MessagingSafetyService
{
    public static function plan(Instance $instance, string $toJid, string $body): ?string
    {
        if (!self::envBool('MESSAGE_SAFETY_ENABLED', true)) {
            return null;
        }

        $db = App::$app->db;
        $instanceId = (int) $instance->id;

        // Serializes enqueue decisions for one instance inside the caller transaction.
        $lock = $db->prepare('SELECT id FROM instances WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $instanceId]);
        if (!$lock->fetchColumn()) {
            throw new \RuntimeException('Instance not found while applying messaging safety policy');
        }

        self::assertQueueCapacity($instanceId);
        self::assertDailyQuota($instanceId);
        self::assertDailyRecipientQuota($instanceId, $toJid);
        self::assertNotDuplicate($instanceId, $toJid, $body);

        return self::nextScheduledAt($instanceId, $toJid);
    }

    public static function assertConsent(Instance $instance, string $toJid, string $chatType): void
    {
        if ($chatType !== 'user' || !self::envBool('MESSAGE_REQUIRE_OPT_IN', true)) {
            return;
        }

        $status = RecipientConsent::status((int) $instance->id, $toJid);
        if ($status !== 'opted_in') {
            $reason = $status === 'opted_out' ? 'recipient opted out' : 'recipient has no recorded opt-in';
            throw new \RuntimeException("Messaging safety: {$reason}");
        }
    }

    private static function assertQueueCapacity(int $instanceId): void
    {
        $limit = self::envInt('MESSAGE_MAX_PENDING_PER_INSTANCE', 500, 1, 100000);
        $stmt = App::$app->db->prepare("
            SELECT COUNT(*)
            FROM send_queue
            WHERE instance_id = :instance_id
              AND status IN ('pending', 'processing')
        ");
        $stmt->execute(['instance_id' => $instanceId]);

        if ((int) $stmt->fetchColumn() >= $limit) {
            throw new \RuntimeException('Messaging safety: instance queue capacity reached');
        }
    }

    private static function assertDailyQuota(int $instanceId): void
    {
        $limit = self::envInt('MESSAGE_MAX_PER_DAY', 500, 1, 1000000);
        $stmt = App::$app->db->prepare("
            SELECT COUNT(*)
            FROM messages
            WHERE instance_id = :instance_id
              AND direction = 'outbound'
              AND created_at >= CURRENT_DATE()
        ");
        $stmt->execute(['instance_id' => $instanceId]);

        if ((int) $stmt->fetchColumn() >= $limit) {
            throw new \RuntimeException('Messaging safety: daily message quota reached');
        }
    }

    private static function assertDailyRecipientQuota(int $instanceId, string $toJid): void
    {
        $limit = self::envInt('MESSAGE_MAX_UNIQUE_RECIPIENTS_PER_DAY', 250, 1, 1000000);
        $stmt = App::$app->db->prepare("
            SELECT
                COUNT(DISTINCT to_jid) AS recipient_count,
                MAX(CASE WHEN to_jid = :to_jid THEN 1 ELSE 0 END) AS recipient_seen
            FROM messages
            WHERE instance_id = :instance_id
              AND direction = 'outbound'
              AND created_at >= CURRENT_DATE()
        ");
        $stmt->execute(['instance_id' => $instanceId, 'to_jid' => $toJid]);
        $row = $stmt->fetch() ?: [];

        if ((int) ($row['recipient_seen'] ?? 0) === 0 && (int) ($row['recipient_count'] ?? 0) >= $limit) {
            throw new \RuntimeException('Messaging safety: daily unique recipient quota reached');
        }
    }

    private static function assertNotDuplicate(int $instanceId, string $toJid, string $body): void
    {
        $window = self::envInt('MESSAGE_DUPLICATE_WINDOW_SECONDS', 300, 0, 86400);
        if ($window === 0) {
            return;
        }

        $stmt = App::$app->db->prepare("
            SELECT 1
            FROM messages
            WHERE instance_id = :instance_id
              AND direction = 'outbound'
              AND to_jid = :to_jid
              AND body = :body
              AND status NOT IN ('failed')
              AND created_at >= TIMESTAMPADD(SECOND, -:window_seconds, NOW())
            LIMIT 1
        ");
        $stmt->execute([
            'instance_id' => $instanceId,
            'to_jid' => $toJid,
            'body' => $body,
            'window_seconds' => $window,
        ]);

        if ($stmt->fetchColumn()) {
            throw new \RuntimeException('Messaging safety: duplicate message blocked for this recipient');
        }
    }

    private static function nextScheduledAt(int $instanceId, string $toJid): ?string
    {
        $perMinute = self::envInt('MESSAGE_MAX_PER_MINUTE', 12, 1, 60);
        $minimumInterval = self::envInt('MESSAGE_MIN_INTERVAL_SECONDS', 5, 1, 300);
        $recipientCooldown = self::envInt('MESSAGE_RECIPIENT_COOLDOWN_SECONDS', 10, 1, 3600);
        $minimumInterval = max($minimumInterval, (int) ceil(60 / $perMinute));

        $stmt = App::$app->db->prepare("
            SELECT
                UNIX_TIMESTAMP(MAX(COALESCE(scheduled_at, created_at))) AS last_instance_slot,
                UNIX_TIMESTAMP(MAX(CASE WHEN to_jid = :to_jid THEN COALESCE(scheduled_at, created_at) ELSE NULL END)) AS last_recipient_slot
            FROM send_queue
            WHERE instance_id = :instance_id
              AND status IN ('pending', 'processing', 'sent')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute(['instance_id' => $instanceId, 'to_jid' => $toJid]);
        $row = $stmt->fetch() ?: [];

        $now = time();
        $next = $now;
        if (!empty($row['last_instance_slot'])) {
            $next = max($next, (int) $row['last_instance_slot'] + $minimumInterval);
        }
        if (!empty($row['last_recipient_slot'])) {
            $next = max($next, (int) $row['last_recipient_slot'] + $recipientCooldown);
        }

        if ($next <= $now) {
            return null;
        }

        $format = App::$app->db->prepare('SELECT FROM_UNIXTIME(:scheduled_at)');
        $format->execute(['scheduled_at' => $next]);
        return (string) $format->fetchColumn();
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = $_ENV[$name] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function envInt(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var($_ENV[$name] ?? null, FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        return min(max((int) $value, $minimum), $maximum);
    }
}
