<?php

declare(strict_types=1);

use App\Services\QueueService;
use App\Services\StoredMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueueMediaPayloadTest extends TestCase
{
    #[DataProvider('mediaTypes')]
    public function test_builds_internal_payload_for_each_media_type(string $type): void
    {
        $media = new StoredMedia('outgoing/8/2026-10/abc.bin', 'pedido.pdf', 'application/pdf', 1234);
        $payload = QueueService::buildStoredMediaPayload($type, $media, 'Legenda');

        self::assertSame('outgoing/8/2026-10/abc.bin', $payload['local_media_path']);
        self::assertSame($type, $payload['media_type']);
        self::assertSame('application/pdf', $payload['mime_type']);
        self::assertSame('pedido.pdf', $payload['file_name']);
        self::assertSame('Legenda', $payload['caption']);
    }

    public static function mediaTypes(): array
    {
        return [['image'], ['video'], ['audio'], ['document']];
    }

    public function test_rejects_invalid_local_media_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QueueService::buildStoredMediaPayload('script', new StoredMedia('a', 'a', 'text/plain', 1), null);
    }
}
