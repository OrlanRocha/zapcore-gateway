<?php

namespace App\Services;

use App\Core\App;
use App\Models\Instance;
use App\Models\Message;
use App\Models\SendQueue;

class QueueService
{
    public const MEDIA_TYPES = ['image', 'audio', 'video', 'document'];
    private const MAX_MEDIA_BYTES = 16777216;

    public static function enqueueText(Instance $instance, string $to, string $text): array
    {
        return self::enqueueTextTo($instance, $to, $text, null);
    }

    public static function enqueueTextTo(Instance $instance, string $to, string $text, ?string $chatType = null): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Text message cannot be empty');
        }

        return self::enqueue($instance, $to, 'text', $text, ['text' => $text], $chatType);
    }

    public static function enqueueMedia(Instance $instance, string $to, string $mediaType, string $mediaUrl, ?string $caption = null, ?string $fileName = null): array
    {
        return self::enqueueMediaTo($instance, $to, $mediaType, $mediaUrl, $caption, $fileName, null);
    }

    public static function enqueueMediaTo(Instance $instance, string $to, string $mediaType, string $mediaUrl, ?string $caption = null, ?string $fileName = null, ?string $chatType = null): array
    {
        $mediaType = strtolower(trim($mediaType));
        if (!in_array($mediaType, self::MEDIA_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid media_type');
        }

        if (!filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('media_url must be a valid URL');
        }

        self::assertRemoteMediaUrl($mediaUrl, $mediaType);

        $payload = match ($mediaType) {
            'image' => ['image' => ['url' => $mediaUrl], 'caption' => $caption],
            'audio' => ['audio' => ['url' => $mediaUrl]],
            'video' => ['video' => ['url' => $mediaUrl], 'caption' => $caption],
            'document' => ['document' => ['url' => $mediaUrl], 'fileName' => $fileName ?: basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'file'), 'caption' => $caption],
        };

        return self::enqueue($instance, $to, $mediaType, $caption ?: $mediaUrl, array_filter($payload, static fn($value) => $value !== null), $chatType);
    }

    public static function normalizeJid(string $to): string
    {
        return JidService::normalize($to)['jid'];
    }

    private static function enqueue(Instance $instance, string $to, string $messageType, string $body, array $payload, ?string $chatType = null): array
    {
        if ($instance->status !== 'connected') {
            throw new \RuntimeException('Instance not connected');
        }

        $destination = JidService::normalize($to, $chatType);
        $toJid = $destination['jid'];
        $chatType = $destination['chat_type'];
        $fromJid = $instance->phone_number ? $instance->phone_number . '@s.whatsapp.net' : '';
        $pdo = App::$app->db->pdo;

        MessagingSafetyService::assertConsent($instance, $toJid, $chatType);

        try {
            $pdo->beginTransaction();

            $scheduledAt = MessagingSafetyService::plan($instance, $toJid, $body);

            $messageModel = new Message();
            $message = $messageModel->create([
                'instance_id' => $instance->id,
                'whatsapp_message_id' => 'pending_' . bin2hex(random_bytes(12)),
                'direction' => 'outbound',
                'from_jid' => $fromJid,
                'to_jid' => $toJid,
                'chat_type' => $chatType,
                'message_type' => $messageType,
                'body' => $body,
                'status' => 'pending',
                'raw_json' => ['payload' => $payload, 'chat_type' => $chatType]
            ]);

            $queueModel = new SendQueue();
            $queue = $queueModel->create([
                'instance_id' => $instance->id,
                'message_id' => $message->id,
                'to_jid' => $toJid,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'scheduled_at' => $scheduledAt,
            ]);

            App::$app->db->prepare("UPDATE messages SET status = 'queued' WHERE id = :id")
                ->execute(['id' => $message->id]);
            $message->status = 'queued';

            $pdo->commit();

            return [
                'message' => $message,
                'queue' => $queue,
                'to_jid' => $toJid,
                'chat_type' => $chatType,
                'scheduled_at' => $scheduledAt,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function assertRemoteMediaUrl(string $url, string $mediaType): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('media_url must use http or https');
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', 'example.com', 'www.example.com'], true)) {
            throw new \InvalidArgumentException('media_url must be a public reachable media URL');
        }

        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException('media_url cannot point to private or reserved networks');
        }

        $probe = self::probeUrl($url, 'HEAD');
        if ($probe['status'] === 405 || $probe['status'] === 403 || $probe['status'] === 0) {
            $probe = self::probeUrl($url, 'GET');
        }

        if ($probe['status'] < 200 || $probe['status'] >= 300) {
            throw new \InvalidArgumentException("media_url is not reachable, HTTP {$probe['status']}");
        }

        if ($probe['length'] !== null && $probe['length'] > self::MAX_MEDIA_BYTES) {
            throw new \InvalidArgumentException('media_url file is too large; maximum is 16MB');
        }

        if ($probe['content_type'] !== null && !self::contentTypeMatches($mediaType, $probe['content_type'])) {
            throw new \InvalidArgumentException("media_url content type does not match {$mediaType}");
        }
    }

    private static function probeUrl(string $url, string $method): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'ZapCore-Gateway/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);

        if ($method === 'GET') {
            $received = 0;
            curl_setopt($ch, CURLOPT_RANGE, '0-0');
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use (&$received) {
                $received += strlen($chunk);
                return $received > 1024 ? 0 : strlen($chunk);
            });
        }

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ($headers['content-type'] ?? '')));
        $contentLength = $headers['content-length'] ?? null;
        curl_close($ch);

        return [
            'status' => $status,
            'content_type' => $contentType !== '' ? $contentType : null,
            'length' => is_numeric($contentLength) ? (int) $contentLength : null,
        ];
    }

    private static function contentTypeMatches(string $mediaType, string $contentType): bool
    {
        return match ($mediaType) {
            'image' => str_starts_with($contentType, 'image/'),
            'audio' => str_starts_with($contentType, 'audio/'),
            'video' => str_starts_with($contentType, 'video/'),
            'document' => true,
            default => false,
        };
    }
}
