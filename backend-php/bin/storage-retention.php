<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Router;
use App\Models\StorageSetting;
use App\Services\MediaRetentionService;
use App\Services\StorageRetentionCommand;
use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    new App(new Router());
    $settings = StorageSetting::current();
    if (!StorageSetting::isDue($settings)) {
        fwrite(STDOUT, "Storage retention is disabled or not due.\n");
        exit(0);
    }

    $result = (new MediaRetentionService())->run('cron');
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(StorageRetentionCommand::exitCode($result));
} catch (Throwable $e) {
    fwrite(STDERR, 'Storage retention failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
