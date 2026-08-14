<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\App;
use App\Core\Auth;

class DashboardController extends Controller
{
    public function index(Request $request, Response $response)
    {
        $db = App::$app->db;
        $userId = (int) Auth::user()->id;

        $instanceStatsStmt = $db->prepare("
            SELECT
                SUM(status = 'connected') AS connected_count,
                SUM(status IN ('created', 'disconnected', 'logged_out', 'error')) AS disconnected_count
            FROM instances
            WHERE user_id = :user_id
        ");
        $instanceStatsStmt->execute(['user_id' => $userId]);
        $instanceStats = $instanceStatsStmt->fetch();

        $messageStatsStmt = $db->prepare("
            SELECT
                SUM(m.status = 'sent') AS sent_today_count,
                SUM(m.direction = 'inbound') AS received_today_count,
                SUM(m.status = 'failed') AS failed_count
            FROM messages m
            JOIN instances i ON i.id = m.instance_id
            WHERE i.user_id = :user_id
              AND m.created_at >= CURDATE()
              AND m.created_at < CURDATE() + INTERVAL 1 DAY
        ");
        $messageStatsStmt->execute(['user_id' => $userId]);
        $messageStats = $messageStatsStmt->fetch();

        $messageBreakdownStmt = $db->prepare("
            SELECT
                COALESCE(m.chat_type, 'unknown') AS chat_type,
                SUM(m.status = 'sent') AS sent_today_count,
                SUM(m.direction = 'inbound') AS received_today_count,
                SUM(m.status = 'failed') AS failed_count
            FROM messages m
            JOIN instances i ON i.id = m.instance_id
            WHERE i.user_id = :user_id
              AND m.created_at >= CURDATE()
              AND m.created_at < CURDATE() + INTERVAL 1 DAY
            GROUP BY COALESCE(m.chat_type, 'unknown')
        ");
        $messageBreakdownStmt->execute(['user_id' => $userId]);
        $messageBreakdown = self::emptyBreakdown();
        foreach ($messageBreakdownStmt->fetchAll() as $row) {
            $type = self::normalizeChatType($row['chat_type'] ?? 'unknown');
            $messageBreakdown['sent'][$type] = (int) ($row['sent_today_count'] ?? 0);
            $messageBreakdown['received'][$type] = (int) ($row['received_today_count'] ?? 0);
            $messageBreakdown['failed'][$type] = (int) ($row['failed_count'] ?? 0);
        }

        $connectedCount = (int) ($instanceStats['connected_count'] ?? 0);
        $disconnectedCount = (int) ($instanceStats['disconnected_count'] ?? 0);
        $sentTodayCount = (int) ($messageStats['sent_today_count'] ?? 0);
        $receivedTodayCount = (int) ($messageStats['received_today_count'] ?? 0);
        $failedCount = (int) ($messageStats['failed_count'] ?? 0);
        $webhookErrorStmt = $db->prepare("
            SELECT COUNT(*)
            FROM webhook_logs wl
            JOIN instances i ON i.id = wl.instance_id
            WHERE i.user_id = :user_id
              AND wl.success = 0
              AND wl.created_at >= CURDATE()
              AND wl.created_at < CURDATE() + INTERVAL 1 DAY
        ");
        $webhookErrorStmt->execute(['user_id' => $userId]);
        $webhookErrorCount = (int) $webhookErrorStmt->fetchColumn();

        $view = 'dashboard/index';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    private static function emptyBreakdown(): array
    {
        return [
            'sent' => self::zeroTypes(),
            'received' => self::zeroTypes(),
            'failed' => self::zeroTypes(),
        ];
    }

    private static function zeroTypes(): array
    {
        return [
            'user' => 0,
            'group' => 0,
            'newsletter' => 0,
            'unknown' => 0,
        ];
    }

    private static function normalizeChatType(string $type): string
    {
        return in_array($type, ['user', 'group', 'newsletter'], true) ? $type : 'unknown';
    }
}
