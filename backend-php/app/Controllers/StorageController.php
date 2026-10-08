<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\StorageCleanupRun;
use App\Models\StorageSetting;
use App\Services\MediaRetentionService;
use App\Services\StorageMetricsService;

final class StorageController extends Controller
{
    public function index(Request $request, Response $response): string
    {
        $settings = StorageSetting::current();
        $metrics = (new StorageMetricsService())->snapshot();
        $history = StorageCleanupRun::recent();
        $nextRunAt = self::nextRunAt($settings);
        $pageTitle = 'Armazenamento | ZapCore Gateway';
        $view = 'storage/index';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return (string) ob_get_clean();
    }

    public function settings(Request $request, Response $response): never
    {
        try {
            StorageSetting::save(self::normalizeSettingsInput($request->getBody()), Auth::user()?->id);
            $response->success([], 'Configuracao de retencao atualizada');
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 422);
        }
        exit;
    }

    public function dryRun(Request $request, Response $response): never
    {
        $this->executeCleanup($response, true);
    }

    public function cleanup(Request $request, Response $response): never
    {
        $this->executeCleanup($response, false);
    }

    public static function normalizeSettingsInput(array $input): array
    {
        if (($input['mode'] ?? 'percent') !== 'absolute') return StorageSetting::validate($input);

        $unit = strtoupper(trim((string) ($input['absolute_unit'] ?? '')));
        $multipliers = ['MB' => 1024 ** 2, 'GB' => 1024 ** 3];
        if (!isset($multipliers[$unit])) throw new \InvalidArgumentException('Use MB ou GB para o limite absoluto');
        $value = filter_var($input['absolute_value'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value <= 0) throw new \InvalidArgumentException('Informe um limite absoluto maior que zero');

        return StorageSetting::validate([
            'enabled' => !empty($input['enabled']),
            'mode' => 'absolute',
            'absolute_bytes' => (int) round($value * $multipliers[$unit]),
            'interval_minutes' => $input['interval_minutes'] ?? 60,
        ]);
    }

    public static function nextRunAt(array $settings): ?\DateTimeImmutable
    {
        if (empty($settings['enabled'])) return null;
        if (empty($settings['last_run_at'])) return new \DateTimeImmutable();
        return (new \DateTimeImmutable((string) $settings['last_run_at']))
            ->modify('+' . (int) $settings['interval_minutes'] . ' minutes');
    }

    private function executeCleanup(Response $response, bool $dryRun): never
    {
        try {
            $result = (new MediaRetentionService())->run('manual', $dryRun, Auth::user()?->id);
            $response->success($result, $dryRun ? 'Simulacao concluida' : 'Limpeza concluida');
        } catch (\Throwable $e) {
            $response->error('Falha ao executar retencao: ' . $e->getMessage(), 500);
        }
        exit;
    }
}
