<?php

namespace App\Services\Network;

final class SafeHttpClient
{
    public function __construct(
        private readonly PublicUrlPolicy $policy = new PublicUrlPolicy(),
        private readonly HttpTransport $transport = new CurlHttpTransport(),
    ) {
    }

    /** @param resource $target */
    public function download(string $url, $target, int $maxBytes, int $maxRedirects = 3): HttpResponse
    {
        if (!is_resource($target) || $maxBytes < 1 || $maxRedirects < 0 || $maxRedirects > 3) {
            throw new \InvalidArgumentException('Invalid safe download options');
        }

        $currentUrl = $url;
        for ($redirects = 0; ; $redirects++) {
            $this->resetTarget($target);
            $destination = $this->policy->resolve($currentUrl);
            $response = $this->transport->send(
                $destination,
                'GET',
                ['Accept: */*'],
                null,
                $target,
                $maxBytes,
            );

            if (!$response->isRedirect()) {
                return $response;
            }
            if ($redirects >= $maxRedirects) {
                throw new PublicUrlException('The remote destination exceeded the redirect limit');
            }

            $location = $response->firstHeader('location');
            if ($location === null || $location === '') {
                throw new PublicUrlException('The remote destination returned an invalid redirect');
            }
            $currentUrl = $this->resolveRedirect($destination->url, $location);
        }
    }

    public function postJson(string $url, string $body, array $headers, int $maxResponseBytes): HttpResponse
    {
        $target = fopen('php://temp/maxmemory:' . max(1, $maxResponseBytes), 'w+b');
        if ($target === false) {
            throw new \RuntimeException('Unable to allocate HTTP response buffer');
        }

        try {
            $destination = $this->policy->resolve($url);
            $response = $this->transport->send(
                $destination,
                'POST',
                array_values(array_unique(array_merge(['Content-Type: application/json'], $headers))),
                $body,
                $target,
                $maxResponseBytes,
            );
            if ($response->isRedirect()) {
                throw new PublicUrlException('Webhook redirects are not allowed');
            }
            rewind($target);
            return new HttpResponse($response->status, $response->headers, $response->bytes, (string) stream_get_contents($target));
        } finally {
            fclose($target);
        }
    }

    private function resetTarget($target): void
    {
        if (!rewind($target) || !ftruncate($target, 0)) {
            throw new \RuntimeException('Unable to reset HTTP response target');
        }
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            throw new PublicUrlException('The remote destination returned an invalid redirect');
        }
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        $host = str_contains((string) $base['host'], ':') ? '[' . $base['host'] . ']' : $base['host'];
        $authority = $base['scheme'] . '://' . $host . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '/')) {
            return $authority . $this->normalizePath($location);
        }
        if (str_starts_with($location, '?')) {
            return $authority . ($base['path'] ?? '/') . $location;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        return $authority . $this->normalizePath($directory . $location);
    }

    private function normalizePath(string $path): string
    {
        $query = '';
        if (($position = strpos($path, '?')) !== false) {
            $query = substr($path, $position);
            $path = substr($path, 0, $position);
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments) . $query;
    }
}
