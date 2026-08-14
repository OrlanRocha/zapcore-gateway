<?php

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Services\TokenService;

class ApiTokenMiddleware
{
    public function handle(Request $request, Response $response)
    {
        $token = $request->getBearerToken();
        if (!$token) {
            return $response->json(['success' => false, 'error' => 'No API token provided'], 401);
        }

        $apiToken = TokenService::findActiveToken($token);

        if (!$apiToken) {
            return $response->json(['success' => false, 'error' => 'Invalid API token'], 401);
        }

        if (!TokenService::assertRateLimit($apiToken['token_hash'])) {
            return $response->json(['success' => false, 'error' => 'Rate limit exceeded'], 429);
        }

        $request->setAttribute('api_token', $apiToken);
        $request->setAttribute('api_user_id', (int) $apiToken['user_id']);
    }
}
