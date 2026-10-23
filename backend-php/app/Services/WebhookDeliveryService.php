<?php

namespace App\Services;

use App\Models\Instance;
use App\Services\Network\PublicUrlException;
use App\Services\Network\SafeHttpClient;

final class WebhookDeliveryService
{
    public const MAX_RESPONSE_BYTES = 4096;

    public function __construct(private readonly SafeHttpClient $http = new SafeHttpClient())
    {
    }

    public function deliver(array $webhook, Instance $instance, string $eventName, array $data): WebhookDeliveryResult
    {
        $payload = json_encode([
            'event' => $eventName,
            'instance_uuid' => $instance->uuid,
            'timestamp' => date('c'),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, (string) $webhook['secret']);

        try {
            $response = $this->http->postJson(
                (string) $webhook['url'],
                $payload,
                ['X-ZapCore-Signature: ' . $signature],
                self::MAX_RESPONSE_BYTES,
            );
        } catch (PublicUrlException) {
            return new WebhookDeliveryResult(
                $payload,
                0,
                null,
                false,
                'Webhook destination blocked by security policy',
            );
        } catch (\Throwable) {
            return new WebhookDeliveryResult($payload, 0, null, false, 'Webhook delivery failed');
        }

        $success = $response->status >= 200 && $response->status < 300;
        return new WebhookDeliveryResult(
            $payload,
            $response->status,
            $this->safeResponseBody($response->firstHeader('content-type'), $response->body),
            $success,
            $success ? null : 'Webhook endpoint returned an unsuccessful status',
        );
    }

    private function safeResponseBody(?string $contentType, string $body): ?string
    {
        $contentType = strtolower((string) $contentType);
        $textual = str_starts_with($contentType, 'text/')
            || str_contains($contentType, 'json')
            || str_contains($contentType, 'xml');
        if (!$textual || $body === '') {
            return null;
        }

        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body) ?? '';
        $body = preg_replace('/(?i)(authorization\s*:\s*(?:bearer|basic)\s+)[^\s]+/', '$1[REDACTED]', $body) ?? $body;
        $body = preg_replace('/(?i)((?:api[_-]?key|token|secret|password)\s*[=:]\s*)[^\s,;&]+/', '$1[REDACTED]', $body) ?? $body;
        $body = preg_replace('/(?i)("(?:api[_-]?key|token|secret|password)"\s*:\s*")[^"]*(")/', '$1[REDACTED]$2', $body) ?? $body;
        return mb_substr($body, 0, self::MAX_RESPONSE_BYTES, 'UTF-8');
    }
}
