<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MediaUploadApiTest extends TestCase
{
    public function test_api_and_web_controllers_support_upload_xor_url_contract(): void
    {
        $api = file_get_contents(ZAPCORE_PROJECT_ROOT . '/backend-php/app/Controllers/ApiController.php') ?: '';
        $web = file_get_contents(ZAPCORE_PROJECT_ROOT . '/backend-php/app/Controllers/MessageController.php') ?: '';

        foreach ([$api, $web] as $source) {
            self::assertStringContainsString("getUploadedFile('media')", $source);
            self::assertStringContainsString('Provide either media or media_url, not both', $source);
            self::assertStringContainsString('A media file or media_url is required', $source);
            self::assertStringContainsString('enqueueStoredMediaTo', $source);
        }
        self::assertStringNotContainsString("['instance_uuid', 'to', 'media_type', 'media_url']", $api);
    }

    public function test_media_reads_expose_retention_state(): void
    {
        $root = dirname(__DIR__, 2);
        $api = file_get_contents($root . '/app/Controllers/ApiController.php');
        $web = file_get_contents($root . '/app/Controllers/MessageController.php');

        foreach ([$api, $web] as $controller) {
            $this->assertStringContainsString('media_removed', $controller);
            $this->assertStringContainsString('media_removed_at', $controller);
            $this->assertStringContainsString('Media removed by retention policy', $controller);
            $this->assertStringContainsString('410', $controller);
        }
    }
}
