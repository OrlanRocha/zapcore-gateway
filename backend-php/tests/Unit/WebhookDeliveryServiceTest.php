<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Services\Network\HttpResponse;
use App\Services\Network\HttpTransport;
use App\Services\Network\PublicUrlPolicy;
use App\Services\Network\ResolvedUrl;
use App\Services\Network\SafeHttpClient;
use App\Services\WebhookDeliveryService;
use PHPUnit\Framework\TestCase;

final class WebhookHttpTransport implements HttpTransport
{
    public array $requests = [];

    public function __construct(private readonly HttpResponse $response)
    {
    }

    public function send(ResolvedUrl $destination, string $method, array $headers, ?string $body, $target, int $maxBytes): HttpResponse
    {
        $this->requests[] = compact('destination', 'method', 'headers', 'body', 'maxBytes');
        if ($this->response->body !== '') fwrite($target, $this->response->body);
        return $this->response;
    }
}

final class WebhookDeliveryServiceTest extends TestCase
{
    public function testSendsSignedPayloadAndSanitizesBoundedTextResponse(): void
    {
        $body = "Authorization: Bearer super-secret\n" . str_repeat('x', 5000);
        $transport = new WebhookHttpTransport(new HttpResponse(200, ['content-type' => ['text/plain']], strlen($body), $body));
        $service = $this->service($transport, static fn(string $host): array => ['93.184.216.34']);
        $instance = $this->instance();

        $result = $service->deliver(
            ['url' => 'https://hooks.example.test/incoming', 'secret' => 'hook-secret'],
            $instance,
            'message.sent',
            ['id' => 10],
        );

        self::assertTrue($result->success);
        self::assertSame(200, $result->status);
        self::assertLessThanOrEqual(4096, strlen((string) $result->responseBody));
        self::assertStringNotContainsString('super-secret', (string) $result->responseBody);
        $payload = $transport->requests[0]['body'];
        $expected = hash_hmac('sha256', $payload, 'hook-secret');
        self::assertContains('X-ZapCore-Signature: ' . $expected, $transport->requests[0]['headers']);
        self::assertSame('message.sent', json_decode($payload, true)['event']);
    }

    public function testBlocksLegacyPrivateWebhookWithoutCallingTransportOrRecordingBody(): void
    {
        $transport = new WebhookHttpTransport(new HttpResponse(200, ['content-type' => ['text/plain']], 6, 'secret'));
        $service = $this->service($transport, static fn(string $host): array => ['127.0.0.1']);

        $result = $service->deliver(
            ['url' => 'https://legacy.example.test/admin', 'secret' => 'secret'],
            $this->instance(),
            'message.sent',
            [],
        );

        self::assertFalse($result->success);
        self::assertSame(0, $result->status);
        self::assertNull($result->responseBody);
        self::assertSame('Webhook destination blocked by security policy', $result->error);
        self::assertSame([], $transport->requests);
    }

    public function testDoesNotStoreBinaryResponseBody(): void
    {
        $transport = new WebhookHttpTransport(new HttpResponse(200, ['content-type' => ['application/octet-stream']], 4, "\x00\x01\x02\x03"));
        $service = $this->service($transport, static fn(string $host): array => ['93.184.216.34']);

        $result = $service->deliver(
            ['url' => 'https://hooks.example.test/incoming', 'secret' => 'secret'],
            $this->instance(),
            'message.sent',
            [],
        );

        self::assertTrue($result->success);
        self::assertNull($result->responseBody);
    }

    private function service(WebhookHttpTransport $transport, callable $resolver): WebhookDeliveryService
    {
        return new WebhookDeliveryService(new SafeHttpClient(new PublicUrlPolicy($resolver), $transport));
    }

    private function instance(): Instance
    {
        $instance = new Instance();
        $instance->id = 1;
        $instance->uuid = 'instance-uuid';
        return $instance;
    }
}
