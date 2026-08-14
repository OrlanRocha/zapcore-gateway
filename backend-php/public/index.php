<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\App;
use App\Core\Router;

session_start();

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$router = new Router();

require_once __DIR__ . '/../routes/web.php';
require_once __DIR__ . '/../routes/api.php';

$app = new App($router);
$app->run();
