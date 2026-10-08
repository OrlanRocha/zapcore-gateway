<?php

namespace App\Models;

use App\Core\App;

final class StorageSetting extends Model
{
    protected string $table = 'storage_settings';
    public const INTERVALS = [5, 15, 30, 60, 360, 1440];

    public static function current(): array
    {
        $row = App::$app->db->pdo->query('SELECT * FROM storage_settings WHERE id = 1')->fetch();
        if (!$row) throw new \RuntimeException('Storage settings are not initialized');
        return $row;
    }

    public static function validate(array $input): array
    {
        $mode = ($input['mode'] ?? 'percent') === 'absolute' ? 'absolute' : 'percent';
        $interval = (int) ($input['interval_minutes'] ?? 60);
        if (!in_array($interval, self::INTERVALS, true)) throw new \InvalidArgumentException('Invalid retention interval');
        $result = ['enabled' => !empty($input['enabled']), 'mode' => $mode, 'interval_minutes' => $interval];

        if ($mode === 'percent') {
            $threshold = (int) ($input['percent_threshold'] ?? 80);
            $target = (int) ($input['percent_target'] ?? 75);
            if ($threshold < 1 || $threshold > 99 || $target < 0 || $target >= $threshold) {
                throw new \InvalidArgumentException('Safety target must be lower than the percentage threshold');
            }
            return $result + ['percent_threshold' => $threshold, 'percent_target' => $target, 'absolute_bytes' => null];
        }

        $bytes = (int) ($input['absolute_bytes'] ?? 0);
        if ($bytes <= 0) throw new \InvalidArgumentException('Absolute retention limit must be greater than zero');
        return $result + ['percent_threshold' => null, 'percent_target' => null, 'absolute_bytes' => $bytes];
    }

    public static function isDue(array $settings, ?\DateTimeImmutable $now = null): bool
    {
        if (empty($settings['enabled'])) return false;
        if (empty($settings['last_run_at'])) return true;

        $interval = (int) ($settings['interval_minutes'] ?? 0);
        if (!in_array($interval, self::INTERVALS, true)) return false;
        $lastRun = new \DateTimeImmutable((string) $settings['last_run_at']);
        return $lastRun->modify("+{$interval} minutes") <= ($now ?? new \DateTimeImmutable());
    }

    public static function save(array $settings, ?int $userId): void
    {
        $settings = self::validate($settings);
        App::$app->db->prepare('UPDATE storage_settings SET enabled=:enabled, mode=:mode, percent_threshold=:percent_threshold, percent_target=:percent_target, absolute_bytes=:absolute_bytes, interval_minutes=:interval_minutes, updated_by_user_id=:user_id WHERE id=1')->execute([
            'enabled' => $settings['enabled'] ? 1 : 0, 'mode' => $settings['mode'],
            'percent_threshold' => $settings['percent_threshold'], 'percent_target' => $settings['percent_target'],
            'absolute_bytes' => $settings['absolute_bytes'], 'interval_minutes' => $settings['interval_minutes'], 'user_id' => $userId,
        ]);
    }
}
