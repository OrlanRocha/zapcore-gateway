<?php

namespace App\Services;

use App\Core\App;
use App\Models\Instance;
use App\Models\Message;
use App\Models\SendQueue;

class QueueService
{
    public const MEDIA_TYPES = ['image', 'audio', 'video', 'document'];

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

        return (new RemoteMediaDownloadService())->downloadAndUse(
            $mediaUrl,
            (int) $instance->id,
            $mediaType,
            $fileName,
            static fn(StoredMedia $stored): array => self::enqueueStoredMediaTo(
                $instance,
                $to,
                $mediaType,
                $stored,
                $caption,
                $chatType,
            ),
        );
    }

    public static function enqueueStoredMediaTo(Instance $instance, string $to, string $mediaType, StoredMedia $media, ?string $caption = null, ?string $chatType = null): array
    {
        $payload = self::buildStoredMediaPayload($mediaType, $media, $caption);

        return self::enqueue($instance, $to, strtolower(trim($mediaType)), $caption ?: $media->displayName, $payload, $chatType, $media);
    }

    public static function buildStoredMediaPayload(string $mediaType, StoredMedia $media, ?string $caption = null): array
    {
        $mediaType = strtolower(trim($mediaType));
        if (!in_array($mediaType, self::MEDIA_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid media_type');
        }

        return array_filter([
            'local_media_path' => $media->relativePath,
            'media_type' => $mediaType,
            'mime_type' => $media->mimeType,
            'file_name' => $media->displayName,
            'caption' => $caption,
        ], static fn($value) => $value !== null);
    }

    public static function normalizeJid(string $to): string
    {
        return JidService::normalize($to)['jid'];
    }

    private static function enqueue(Instance $instance, string $to, string $messageType, string $body, array $payload, ?string $chatType = null, ?StoredMedia $storedMedia = null): array
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

            if ($storedMedia !== null) {
                App::$app->db->prepare("\n                    INSERT INTO message_media\n                        (message_id, file_path, file_name, mime_type, file_size, storage_origin)\n                    VALUES\n                        (:message_id, :file_path, :file_name, :mime_type, :file_size, 'outgoing')\n                ")->execute([
                    'message_id' => $message->id,
                    'file_path' => $storedMedia->relativePath,
                    'file_name' => $storedMedia->displayName,
                    'mime_type' => $storedMedia->mimeType,
                    'file_size' => $storedMedia->size,
                ]);
            }

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

}
