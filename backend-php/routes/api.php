<?php

use App\Controllers\ApiController;
use App\Middlewares\ApiTokenMiddleware;
use App\Middlewares\InternalApiMiddleware;

global $router;

// Public API
$router->get('/api/instances', [ApiController::class, 'listInstances'], ApiTokenMiddleware::class);
$router->post('/api/instances', [ApiController::class, 'createInstance'], ApiTokenMiddleware::class);
$router->get('/api/instances/{uuid}/status', [ApiController::class, 'instanceStatus'], ApiTokenMiddleware::class);
$router->get('/api/instances/{uuid}/qr', [ApiController::class, 'instanceQr'], ApiTokenMiddleware::class);
$router->post('/api/instances/{uuid}/connect', [ApiController::class, 'connectInstance'], ApiTokenMiddleware::class);
$router->post('/api/instances/{uuid}/disconnect', [ApiController::class, 'disconnectInstance'], ApiTokenMiddleware::class);

$router->post('/api/messages/text', [ApiController::class, 'sendText'], ApiTokenMiddleware::class);
$router->post('/api/messages/media', [ApiController::class, 'sendMedia'], ApiTokenMiddleware::class);
$router->get('/api/messages', [ApiController::class, 'listMessages'], ApiTokenMiddleware::class);
$router->get('/api/messages/{id}/media', [ApiController::class, 'media'], ApiTokenMiddleware::class);

$router->post('/api/webhooks', [ApiController::class, 'createWebhook'], ApiTokenMiddleware::class);
$router->get('/api/webhook-logs', [ApiController::class, 'webhookLogs'], ApiTokenMiddleware::class);

// Internal Worker API
$router->post('/internal/instances/{uuid}/status', [ApiController::class, 'internalUpdateStatus'], InternalApiMiddleware::class);
$router->post('/internal/instances/{uuid}/qr', [ApiController::class, 'internalUpdateStatus'], InternalApiMiddleware::class);
$router->post('/internal/messages/received', [ApiController::class, 'internalMessageReceived'], InternalApiMiddleware::class);
$router->post('/internal/messages/status', [ApiController::class, 'internalMessageStatus'], InternalApiMiddleware::class);
$router->post('/internal/contacts/sync', [ApiController::class, 'internalContactsSync'], InternalApiMiddleware::class);
$router->post('/internal/connection-log', [ApiController::class, 'internalConnectionLog'], InternalApiMiddleware::class);
