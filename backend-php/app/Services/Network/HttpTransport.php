<?php

namespace App\Services\Network;

interface HttpTransport
{
    /** @param resource $target */
    public function send(
        ResolvedUrl $destination,
        string $method,
        array $headers,
        ?string $body,
        $target,
        int $maxBytes,
    ): HttpResponse;
}
