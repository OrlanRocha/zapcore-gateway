<?php

namespace App\Services;

final class WebhookDeliveryResult
{
    public function __construct(
        public readonly string $payload,
        public readonly int $status,
        public readonly ?string $responseBody,
        public readonly bool $success,
        public readonly ?string $error,
    ) {
    }
}
