<?php

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\InstanceController;
use App\Controllers\MessageController;
use App\Controllers\ProfileController;
use App\Controllers\SetupController;
use App\Controllers\UserController;
use App\Controllers\WebhookController;
use App\Middlewares\AdminMiddleware;
use App\Middlewares\AuthMiddleware;

global $router;

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/setup', [SetupController::class, 'setup']);
$router->post('/setup', [SetupController::class, 'store']);
$router->get('/first-login', [SetupController::class, 'firstLogin'], AuthMiddleware::class);
$router->post('/first-login', [SetupController::class, 'completeFirstLogin'], AuthMiddleware::class);

$router->get('/', function($req, $res) { $res->redirect('/dashboard'); }, AuthMiddleware::class);
$router->get('/dashboard', [DashboardController::class, 'index'], AuthMiddleware::class);

$router->get('/profile', [ProfileController::class, 'edit'], AuthMiddleware::class);
$router->post('/profile', [ProfileController::class, 'update'], AuthMiddleware::class);

$router->get('/users', [UserController::class, 'index'], [AuthMiddleware::class, AdminMiddleware::class]);
$router->get('/users/create', [UserController::class, 'create'], [AuthMiddleware::class, AdminMiddleware::class]);
$router->post('/users', [UserController::class, 'store'], [AuthMiddleware::class, AdminMiddleware::class]);
$router->get('/users/{id}/edit', [UserController::class, 'edit'], [AuthMiddleware::class, AdminMiddleware::class]);
$router->post('/users/{id}', [UserController::class, 'update'], [AuthMiddleware::class, AdminMiddleware::class]);
$router->post('/users/{id}/delete', [UserController::class, 'destroy'], [AuthMiddleware::class, AdminMiddleware::class]);

$router->get('/instances', [InstanceController::class, 'index'], AuthMiddleware::class);
$router->get('/instances/create', [InstanceController::class, 'create'], AuthMiddleware::class);
$router->post('/instances', [InstanceController::class, 'store'], AuthMiddleware::class);
$router->get('/instances/{id}', [InstanceController::class, 'show'], AuthMiddleware::class);
$router->post('/instances/{id}/connect', [InstanceController::class, 'connect'], AuthMiddleware::class);
$router->post('/instances/{id}/disconnect', [InstanceController::class, 'disconnect'], AuthMiddleware::class);
$router->post('/instances/{id}/delete', [InstanceController::class, 'destroy'], AuthMiddleware::class);
$router->get('/instances/{id}/qr', [InstanceController::class, 'qr'], AuthMiddleware::class);
$router->post('/instances/{id}/send-test', [InstanceController::class, 'sendTest'], AuthMiddleware::class);
$router->post('/instances/{id}/shares', [InstanceController::class, 'share'], AuthMiddleware::class);
$router->post('/instances/{id}/shares/revoke', [InstanceController::class, 'revokeShare'], AuthMiddleware::class);
$router->get('/instances/{id}/messages', [MessageController::class, 'instance'], AuthMiddleware::class);
$router->get('/messages/{id}/media', [MessageController::class, 'media'], AuthMiddleware::class);
$router->get('/instances/{id}/chat', [MessageController::class, 'chat'], AuthMiddleware::class);
$router->get('/instances/{id}/chat/contacts', [MessageController::class, 'contacts'], AuthMiddleware::class);
$router->post('/instances/{id}/chat/read', [MessageController::class, 'markRead'], AuthMiddleware::class);
$router->post('/instances/{id}/chat/send', [MessageController::class, 'sendChat'], AuthMiddleware::class);
$router->get('/instances/{id}/webhooks', [WebhookController::class, 'instance'], AuthMiddleware::class);
$router->post('/instances/{id}/webhooks', [WebhookController::class, 'storeForInstance'], AuthMiddleware::class);

$router->get('/messages', function($req, $res) { $res->redirect('/instances'); }, AuthMiddleware::class);

$router->get('/webhooks', function($req, $res) { $res->redirect('/instances'); }, AuthMiddleware::class);
