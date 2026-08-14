<?php

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;

class InternalApiMiddleware
{
    public function handle(Request $request, Response $response)
    {
        $secret = $request->getHeader('Internal-Secret');
        $expected = $_ENV['INTERNAL_SECRET'] ?? '';
        if (!$secret || !$expected || !hash_equals($expected, $secret)) {
            return $response->json(['success' => false, 'error' => 'Unauthorized internal API access'], 401);
        }
    }
}
