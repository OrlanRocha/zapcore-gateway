<?php

declare(strict_types=1);

use App\Services\Network\CurlHttpTransport;
use App\Services\Network\PublicUrlException;
use App\Services\Network\ResolvedUrl;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/LocalHttpServer.php';

final class CurlHttpTransportTest extends TestCase
{
    private LocalHttpServer $server;

    protected function setUp(): void
    {
        $this->server = LocalHttpServer::start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        putenv('HTTP_PROXY');
        putenv('HTTPS_PROXY');
    }

    public function testPinsHostnameAndIgnoresEnvironmentProxy(): void
    {
        putenv('HTTP_PROXY=http://127.0.0.1:1');
        putenv('HTTPS_PROXY=http://127.0.0.1:1');
        $destination = new ResolvedUrl(
            "http://public.example.test:{$this->server->port}/ok",
            'http',
            'public.example.test',
            $this->server->port,
            ['127.0.0.1'],
        );
        $target = fopen('php://temp', 'w+b');

        $response = (new CurlHttpTransport(2, 5))->send($destination, 'GET', [], null, $target, 1024);

        rewind($target);
        self::assertSame(200, $response->status);
        self::assertSame('pinned-ok', stream_get_contents($target));
    }

    public function testEnforcesStreamingLimitWithoutTrustingContentLength(): void
    {
        $destination = new ResolvedUrl(
            "http://public.example.test:{$this->server->port}/large",
            'http',
            'public.example.test',
            $this->server->port,
            ['127.0.0.1'],
        );

        $this->expectException(PublicUrlException::class);
        $this->expectExceptionMessage('exceeds');
        (new CurlHttpTransport(2, 5))->send(
            $destination,
            'GET',
            [],
            null,
            fopen('php://temp', 'w+b'),
            1024,
        );
    }
}
