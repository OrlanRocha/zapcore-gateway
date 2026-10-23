<?php

namespace App\Services\Network;

final class ResolvedUrl
{
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
    ) {
    }

    public function resolveEntry(string $address): string
    {
        $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
        return "{$this->host}:{$this->port}:{$target}";
    }
}
