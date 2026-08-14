<?php

namespace App\Services;

use App\Core\App;

class TokenService
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findActiveToken(string $token): ?array
    {
        $stmt = App::$app->db->prepare("
            SELECT at.*, u.active AS user_active
            FROM api_tokens at
            JOIN users u ON u.id = at.user_id
            WHERE at.token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->execute(['token_hash' => self::hash($token)]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['user_active'] !== 1) {
            return null;
        }

        App::$app->db->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id")
            ->execute(['id' => $row['id']]);

        return $row;
    }

    public static function issue(int $userId, string $name = 'Default API token'): array
    {
        $plain = 'zc_' . bin2hex(random_bytes(32));
        $stmt = App::$app->db->prepare("
            INSERT INTO api_tokens (user_id, token_hash, name)
            VALUES (:user_id, :token_hash, :name)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => self::hash($plain),
            'name' => $name
        ]);

        return [
            'token' => $plain,
            'token_hash' => self::hash($plain),
            'id' => (int) App::$app->db->pdo->lastInsertId()
        ];
    }

    public static function assertRateLimit(string $tokenHash, int $limit = 120, int $windowSeconds = 60): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir . '/rate_' . preg_replace('/[^a-f0-9]/', '', $tokenHash) . '.json';
        $now = time();
        $state = ['window_start' => $now, 'count' => 0];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $state = array_merge($state, $decoded);
            }
        }

        if (($now - (int) $state['window_start']) >= $windowSeconds) {
            $state = ['window_start' => $now, 'count' => 0];
        }

        $state['count'] = (int) $state['count'] + 1;
        file_put_contents($file, json_encode($state), LOCK_EX);

        return $state['count'] <= $limit;
    }
}
