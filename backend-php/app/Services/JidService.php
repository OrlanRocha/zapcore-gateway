<?php

namespace App\Services;

class JidService
{
    public const CHAT_TYPES = ['user', 'group', 'newsletter'];

    public static function normalize(string $value, ?string $chatType = null): array
    {
        $value = trim($value);
        $chatType = self::normalizeChatType($chatType);

        if ($value === '') {
            throw new \InvalidArgumentException('Destination is required');
        }

        if (str_contains($value, '@')) {
            $jid = strtolower($value);
            $detected = self::detectChatType($jid);
            if ($detected === 'unknown') {
                throw new \InvalidArgumentException('Unsupported destination JID');
            }
            if ($chatType !== null && $chatType !== $detected) {
                throw new \InvalidArgumentException("Destination JID does not match chat_type {$chatType}");
            }

            return ['jid' => $jid, 'chat_type' => $detected];
        }

        if ($chatType !== null && $chatType !== 'user') {
            throw new \InvalidArgumentException('Group and newsletter sends require a full JID');
        }

        $number = preg_replace('/\D+/', '', $value);
        if ($number === '' || strlen($number) < 10 || strlen($number) > 15) {
            throw new \InvalidArgumentException('Invalid destination number');
        }

        return ['jid' => $number . '@s.whatsapp.net', 'chat_type' => 'user'];
    }

    public static function detectChatType(?string $jid): string
    {
        $jid = strtolower(trim((string) $jid));

        return match (true) {
            str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@c.us') => 'user',
            str_ends_with($jid, '@g.us') => 'group',
            str_ends_with($jid, '@newsletter') => 'newsletter',
            default => 'unknown',
        };
    }

    public static function normalizeChatType(?string $chatType): ?string
    {
        $chatType = strtolower(trim((string) $chatType));
        if ($chatType === '') {
            return null;
        }

        if (!in_array($chatType, self::CHAT_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid chat_type');
        }

        return $chatType;
    }
}
