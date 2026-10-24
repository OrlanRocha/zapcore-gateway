<?php

namespace App\Services\Network;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly int $bytes,
        public readonly string $body = '',
    ) {
    }

    public function firstHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return isset($values[0]) ? trim((string) $values[0]) : null;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true);
    }
}
