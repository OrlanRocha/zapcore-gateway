<?php

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;

class CsrfMiddleware
{
    public function handle(Request $request, Response $response): void
    {
        $fetchSite = strtolower((string) $request->getHeader('Sec-Fetch-Site'));
        if ($fetchSite === 'cross-site') {
            $response->error('Cross-site request blocked', 403);
        }

        $source = $request->getHeader('Origin') ?: $request->getHeader('Referer');
        if (!$source) {
            return;
        }

        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        $requestHost = strtolower((string) parse_url('http://' . $request->getHeader('Host'), PHP_URL_HOST));
        if ($sourceHost === '' || $requestHost === '' || !hash_equals($requestHost, $sourceHost)) {
            $response->error('Cross-site request blocked', 403);
        }
    }
}
