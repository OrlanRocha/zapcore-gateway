<?php

use App\Controllers\StorageController;
use PHPUnit\Framework\TestCase;

final class StorageControllerTest extends TestCase
{
    public function test_storage_routes_are_restricted_to_authenticated_administrators(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');

        foreach (['/storage', '/storage/settings', '/storage/dry-run', '/storage/cleanup'] as $route) {
            $this->assertMatchesRegularExpression(
                '#[\'\"]' . preg_quote($route, '#') . '[\'\"].*AuthMiddleware::class, AdminMiddleware::class#',
                $routes
            );
        }
    }

    public function test_absolute_settings_convert_units_and_discard_percentage_fields(): void
    {
        $settings = StorageController::normalizeSettingsInput([
            'enabled' => '1',
            'mode' => 'absolute',
            'absolute_value' => '2.5',
            'absolute_unit' => 'GB',
            'percent_threshold' => '90',
            'percent_target' => '85',
            'interval_minutes' => '60',
        ]);

        $this->assertSame(2684354560, $settings['absolute_bytes']);
        $this->assertNull($settings['percent_threshold']);
        $this->assertNull($settings['percent_target']);
    }

    public function test_absolute_settings_reject_unknown_units(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StorageController::normalizeSettingsInput([
            'mode' => 'absolute',
            'absolute_value' => '10',
            'absolute_unit' => 'TB',
            'interval_minutes' => '60',
        ]);
    }
}
