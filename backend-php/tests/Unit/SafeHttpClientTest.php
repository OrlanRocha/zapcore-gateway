<?php

declare(strict_types=1);

use App\Services\Network\HttpResponse;
use App\Services\Network\HttpTransport;
use App\Services\Network\PublicUrlException;
use App\Services\Network\PublicUrlPolicy;
use App\Services\Network\ResolvedUrl;
use App\Services\Network\SafeHttpClient;
use PHPUnit\Framework\TestCase;

final class RecordingHttpTransport implements HttpTransport
{
    public array $requests = [];
    public array $responses = [];

    public function send(
        ResolvedUrl $destination,
        string $method,
        array $headers,
        ?string $body,
        $target,
        int $maxBytes,
    ): HttpResponse {
        $this->requests[] = compact('destination', 'method', 'headers', 'body', 'maxBytes');
        $response = array_shift($this->responses) ?? new HttpResponse(200, [], 0);
        if ($response->body !== '' && is_resource($target)) {
            fwrite($target, $response->body);
        }
        return $response;
    }
}

final class SafeHttpClientTest extends TestCase
{
    public function testFollowsAbsoluteAndRelativeRedirectsWithFreshValidation(): void
    {
        $resolvedHosts = [];
        $policy = new PublicUrlPolicy(static function (string $host) use (&$resolvedHosts): array {
            $resolvedHosts[] = $host;
            return ['93.184.216.34'];
        });
        $transport = new RecordingHttpTransport();
        $transport->responses = [
            new HttpResponse(302, ['location' => ['/next']], 0),
            new HttpResponse(301, ['location' => ['https://cdn.example.test/final.jpg']], 0),
            new HttpResponse(200, ['content-type' => ['image/jpeg']], 3, 'abc'),
        ];
        $target = fopen('php://temp', 'w+b');

        $response = (new SafeHttpClient($policy, $transport))->download(
            'https://origin.example.test/start',
            $target,
            1024,
            3,
        );

        rewind($target);
        self::assertSame(200, $response->status);
        self::assertSame('abc', stream_get_contents($target));
        self::assertSame(['origin.example.test', 'origin.example.test', 'cdn.example.test'], $resolvedHosts);
        self::assertCount(3, $transport->requests);
    }

    public function testBlocksRedirectToPrivateDestinationBeforeSecondConnection(): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => $host === 'public.example.test'
            ? ['93.184.216.34']
            : ['127.0.0.1']);
        $transport = new RecordingHttpTransport();
        $transport->responses = [new HttpResponse(302, ['location' => ['http://internal.example.test/admin']], 0)];

        $this->expectException(PublicUrlException::class);
        try {
            (new SafeHttpClient($policy, $transport))->download(
                'https://public.example.test/media',
                fopen('php://temp', 'w+b'),
                1024,
            );
        } finally {
            self::assertCount(1, $transport->requests);
        }
    }

    public function testRejectsMoreThanThreeRedirects(): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => ['93.184.216.34']);
        $transport = new RecordingHttpTransport();
        $transport->responses = array_fill(0, 4, new HttpResponse(302, ['location' => ['/again']], 0));

        $this->expectException(PublicUrlException::class);
        $this->expectExceptionMessage('redirect');
        (new SafeHttpClient($policy, $transport))->download(
            'https://files.example.test/start',
            fopen('php://temp', 'w+b'),
            1024,
            3,
        );
    }

    public function testWebhookPostRejectsRedirectAndPreservesBodyAndHeaders(): void
    {
        $policy = new PublicUrlPolicy(static fn(string $host): array => ['93.184.216.34']);
        $transport = new RecordingHttpTransport();
        $transport->responses = [new HttpResponse(307, ['location' => ['https://other.example.test/hook']], 0)];
        $client = new SafeHttpClient($policy, $transport);

        try {
            $client->postJson('https://hook.example.test/incoming', '{"ok":true}', ['X-Signature: abc'], 4096);
            self::fail('Expected redirect rejection');
        } catch (PublicUrlException $e) {
            self::assertStringContainsString('redirect', strtolower($e->getMessage()));
        }

        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('{"ok":true}', $transport->requests[0]['body']);
        self::assertContains('X-Signature: abc', $transport->requests[0]['headers']);
    }

    public function testPinsTheResolutionReturnedBySinglePolicyDecision(): void
    {
        $calls = 0;
        $policy = new PublicUrlPolicy(static function (string $host) use (&$calls): array {
            $calls++;
            return $calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        });
        $transport = new RecordingHttpTransport();
        $transport->responses = [new HttpResponse(200, [], 0)];

        (new SafeHttpClient($policy, $transport))->download(
            'https://files.example.test/media',
            fopen('php://temp', 'w+b'),
            1024,
        );

        self::assertSame(1, $calls);
        self::assertSame(['93.184.216.34'], $transport->requests[0]['destination']->addresses);
    }
}
