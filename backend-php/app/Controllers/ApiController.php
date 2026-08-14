<?php

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Instance;
use App\Models\Log;
use App\Models\Message;
use App\Models\Webhook;
use App\Services\JidService;
use App\Services\QueueService;
use App\Services\WebhookDispatcher;

class ApiController extends Controller
{
    private const INSTANCE_STATUSES = ['created', 'waiting_qr', 'connecting', 'connected', 'disconnected', 'logged_out', 'error'];
    private const MESSAGE_STATUSES = ['pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'received'];
    private const CHAT_TYPES = ['user', 'group', 'newsletter'];

    public function listInstances(Request $request, Response $response)
    {
        return $response->success([
            'instances' => Instance::allByUser((int) $request->getAttribute('api_user_id'))
        ]);
    }

    public function createInstance(Request $request, Response $response)
    {
        $body = $request->getBody();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            return $response->error('Instance name is required', 422);
        }

        $instanceModel = new Instance();
        $instance = $instanceModel->create([
            'user_id' => (int) $request->getAttribute('api_user_id'),
            'uuid' => self::uuid(),
            'name' => $name,
            'provider' => 'baileys'
        ]);

        return $response->success(['instance' => self::instancePayload($instance)], 'Instance created', 201);
    }

    public function instanceStatus(Request $request, Response $response, string $uuid)
    {
        $instance = $this->findInstanceForApi($request, $uuid);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        return $response->success([
            'uuid' => $instance->uuid,
            'status' => $instance->status,
            'phone_number' => $instance->phone_number,
            'profile_name' => $instance->profile_name,
            'last_connected_at' => $instance->last_connected_at,
            'last_disconnected_at' => $instance->last_disconnected_at
        ]);
    }

    public function instanceQr(Request $request, Response $response, string $uuid)
    {
        $instance = $this->findInstanceForApi($request, $uuid);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        return $response->success([
            'uuid' => $instance->uuid,
            'status' => $instance->status,
            'qr' => $instance->qr_code,
            'qr_updated_at' => $instance->qr_updated_at
        ]);
    }

    public function connectInstance(Request $request, Response $response, string $uuid)
    {
        $body = $request->getBody();
        $instance = $this->findInstanceForApi($request, $uuid);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        $mode = (($body['mode'] ?? 'qr') === 'pin') ? 'pin' : 'qr';
        $payload = ['mode' => $mode];
        $phoneNumber = null;

        if ($mode === 'pin') {
            $phoneNumber = self::normalizePhoneNumber((string) ($body['phone_number'] ?? $body['phoneNumber'] ?? ''));
            if (!$phoneNumber) {
                return $response->error('Valid phone_number with country code is required for PIN Code connection', 422);
            }

            $payload['phone_number'] = $phoneNumber;
        }

        $worker = $this->callWorker("/worker/instances/{$uuid}/connect", $payload);
        if (($worker['success'] ?? false) !== true) {
            $instance->update(['status' => 'error']);
            Log::createConnectionLog($instance->id, 'error', $worker['error'] ?? 'Worker connection failed');
            return $response->error($worker['error'] ?? 'Worker connection failed', 502);
        }

        $workerData = $worker['data'] ?? [];
        $targetStatus = !empty($workerData['already_connected']) ? 'connected' : 'connecting';

        $instance->update([
            'status' => $targetStatus,
            'qr_code' => null,
            'qr_updated_at' => null,
            'phone_number' => $phoneNumber ?? $instance->phone_number
        ]);
        Log::createConnectionLog(
            $instance->id,
            $targetStatus === 'connected' ? 'connected' : 'connecting',
            $targetStatus === 'connected'
                ? 'Connection requested by API; instance was already connected'
                : ($mode === 'pin' ? 'PIN Code connection requested by API' : 'QR Code connection requested by API')
        );

        return $response->success([
            'mode' => $mode,
            'pairing_code' => $workerData['pairing_code'] ?? null,
            'already_connected' => (bool) ($workerData['already_connected'] ?? false),
            'worker' => $worker
        ], !empty($workerData['pairing_code']) ? 'PIN Code generated' : 'Connection initiated');
    }

    public function disconnectInstance(Request $request, Response $response, string $uuid)
    {
        $instance = $this->findInstanceForApi($request, $uuid);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        $worker = $this->callWorker("/worker/instances/{$uuid}/disconnect");
        $instance->update([
            'status' => 'disconnected',
            'last_disconnected_at' => date('Y-m-d H:i:s')
        ]);
        Log::createConnectionLog($instance->id, 'disconnected', 'Disconnect requested by API');
        WebhookDispatcher::dispatch($instance, 'instance.disconnected', ['reason' => 'api_request']);

        return $response->success(['worker' => $worker], 'Instance disconnected');
    }

    public function sendText(Request $request, Response $response)
    {
        $body = $request->getBody();
        foreach (['instance_uuid', 'to', 'text'] as $field) {
            if (!isset($body[$field]) || trim((string) $body[$field]) === '') {
                return $response->error("Missing required field: {$field}", 422);
            }
        }

        $instance = $this->findInstanceForApi($request, $body['instance_uuid']);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        try {
            $queued = QueueService::enqueueTextTo(
                $instance,
                (string) $body['to'],
                (string) $body['text'],
                $body['chat_type'] ?? $body['recipient_type'] ?? null
            );
            return $response->success([
                'message_id' => $queued['message']->id,
                'queue_id' => $queued['queue']->id,
                'to_jid' => $queued['to_jid'],
                'chat_type' => $queued['chat_type']
            ], 'Message queued for sending', 201);
        } catch (\InvalidArgumentException $e) {
            return $response->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $response->error($e->getMessage(), 409);
        }
    }

    public function sendMedia(Request $request, Response $response)
    {
        $body = $request->getBody();
        foreach (['instance_uuid', 'to', 'media_type', 'media_url'] as $field) {
            if (!isset($body[$field]) || trim((string) $body[$field]) === '') {
                return $response->error("Missing required field: {$field}", 422);
            }
        }

        $instance = $this->findInstanceForApi($request, $body['instance_uuid']);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        try {
            $queued = QueueService::enqueueMediaTo(
                $instance,
                (string) $body['to'],
                (string) $body['media_type'],
                (string) $body['media_url'],
                $body['caption'] ?? null,
                $body['file_name'] ?? null,
                $body['chat_type'] ?? $body['recipient_type'] ?? null
            );

            return $response->success([
                'message_id' => $queued['message']->id,
                'queue_id' => $queued['queue']->id,
                'to_jid' => $queued['to_jid'],
                'chat_type' => $queued['chat_type']
            ], 'Media message queued for sending', 201);
        } catch (\InvalidArgumentException $e) {
            return $response->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $response->error($e->getMessage(), 409);
        }
    }

    public function listMessages(Request $request, Response $response)
    {
        $body = $request->getBody();
        $limit = min(max((int) ($body['limit'] ?? 100), 1), 500);
        $page = max((int) ($body['page'] ?? 1), 1);
        $offset = ($page - 1) * $limit;
        $params = ['user_id' => (int) $request->getAttribute('api_user_id')];
        $where = [
            'i.user_id = :user_id',
            "COALESCE(m.raw_json, '') NOT LIKE '%\"Message absent from node\"%'"
        ];

        if (!empty($body['instance_uuid'])) {
            $where[] = 'i.uuid = :uuid';
            $params['uuid'] = $body['instance_uuid'];
        }
        if (!empty($body['status']) && in_array($body['status'], self::MESSAGE_STATUSES, true)) {
            $where[] = 'm.status = :status';
            $params['status'] = $body['status'];
        }
        if (!empty($body['direction']) && in_array($body['direction'], ['inbound', 'outbound'], true)) {
            $where[] = 'm.direction = :direction';
            $params['direction'] = $body['direction'];
        }
        if (!empty($body['chat_type']) && in_array($body['chat_type'], self::CHAT_TYPES, true)) {
            $where[] = 'm.chat_type = :chat_type';
            $params['chat_type'] = $body['chat_type'];
        }
        if (!empty($body['q'])) {
            $search = '%' . substr(trim((string) $body['q']), 0, 100) . '%';
            $where[] = '(m.body LIKE :q_body OR m.from_jid LIKE :q_from OR m.to_jid LIKE :q_to OR m.whatsapp_message_id LIKE :q_wa_id)';
            $params['q_body'] = $search;
            $params['q_from'] = $search;
            $params['q_to'] = $search;
            $params['q_wa_id'] = $search;
        }

        $countStmt = App::$app->db->prepare("
            SELECT COUNT(*)
            FROM messages m
            JOIN instances i ON i.id = m.instance_id
            WHERE " . implode(' AND ', $where) . "
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT
                m.id,
                m.instance_id,
                m.chat_id,
                m.whatsapp_message_id,
                m.direction,
                m.from_jid,
                m.to_jid,
                m.chat_type,
                m.message_type,
                m.body,
                m.status,
                m.error_message,
                m.sent_at,
                m.delivered_at,
                m.read_at,
                m.received_at,
                m.created_at,
                m.updated_at,
                mm.file_name AS media_file_name,
                mm.mime_type AS media_mime_type,
                CASE WHEN mm.id IS NULL THEN NULL ELSE CONCAT('/api/messages/', m.id, '/media') END AS media_url,
                i.uuid AS instance_uuid,
                i.name AS instance_name
            FROM messages m
            JOIN instances i ON i.id = m.instance_id
            LEFT JOIN message_media mm ON mm.message_id = m.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = App::$app->db->prepare($sql);
        $stmt->execute($params);

        return $response->success([
            'messages' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max((int) ceil($total / $limit), 1)
            ]
        ]);
    }

    public function createWebhook(Request $request, Response $response)
    {
        $body = $request->getBody();
        $name = trim($body['name'] ?? '');
        $url = trim($body['url'] ?? '');
        $events = $body['events'] ?? [];

        if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $response->error('Valid name and url are required', 422);
        }

        if (is_string($events)) {
            $events = array_values(array_filter(array_map('trim', explode(',', $events))));
        }
        if (!is_array($events) || !$events) {
            return $response->error('At least one event is required', 422);
        }

        if (empty($body['instance_uuid'])) {
            return $response->error('instance_uuid is required', 422);
        }

        $instance = $this->findInstanceForApi($request, $body['instance_uuid']);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        $id = Webhook::create([
            'instance_id' => $instance->id,
            'name' => $name,
            'url' => $url,
            'secret' => $body['secret'] ?? bin2hex(random_bytes(24)),
            'events' => $events,
            'active' => !isset($body['active']) || (bool) $body['active']
        ]);

        return $response->success(['webhook_id' => $id], 'Webhook created', 201);
    }

    public function webhookLogs(Request $request, Response $response)
    {
        return $response->success(['logs' => Webhook::logsByUser((int) $request->getAttribute('api_user_id'))]);
    }

    public function internalUpdateStatus(Request $request, Response $response, string $uuid)
    {
        $body = $request->getBody();
        $status = $body['status'] ?? null;
        $qr = $body['qr'] ?? null;

        $instanceModel = new Instance();
        $instance = $instanceModel->findByUuid($uuid);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        $updates = [];
        if ($status !== null) {
            if (!in_array($status, self::INSTANCE_STATUSES, true)) {
                return $response->error('Invalid status', 422);
            }
            $updates['status'] = $status;
        }
        if ($qr !== null) {
            $updates['qr_code'] = $qr;
            $updates['qr_updated_at'] = date('Y-m-d H:i:s');
            $updates['status'] = $status ?: 'waiting_qr';
        }
        if (!empty($body['phone_number'])) {
            $updates['phone_number'] = preg_replace('/\D+/', '', (string) $body['phone_number']);
        }
        if (!empty($body['profile_name'])) {
            $updates['profile_name'] = trim((string) $body['profile_name']);
        }
        if (($updates['status'] ?? null) === 'connected') {
            $updates['last_connected_at'] = date('Y-m-d H:i:s');
            $updates['qr_code'] = null;
        }
        if (in_array($updates['status'] ?? '', ['disconnected', 'logged_out', 'error'], true)) {
            $updates['last_disconnected_at'] = date('Y-m-d H:i:s');
        }

        $instance->update($updates);
        $eventType = self::connectionEventType($updates['status'] ?? ($qr ? 'waiting_qr' : 'connecting'));
        Log::createConnectionLog($instance->id, $eventType, $body['description'] ?? '', $body);

        foreach (self::instanceWebhookEvents($updates['status'] ?? null, $qr) as $event) {
            WebhookDispatcher::dispatch($instance, $event, [
                'status' => $instance->status,
                'qr_updated_at' => $instance->qr_updated_at,
                'phone_number' => $instance->phone_number,
                'profile_name' => $instance->profile_name
            ]);
        }

        return $response->success();
    }

    public function internalMessageReceived(Request $request, Response $response)
    {
        $body = $request->getBody();
        if (empty($body['instance_uuid']) || empty($body['message'])) {
            return $response->error('Invalid payload', 422);
        }

        $instanceModel = new Instance();
        $instance = $instanceModel->findByUuid($body['instance_uuid']);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        $message = $body['message'];
        if (!is_array($message['message'] ?? null)) {
            return $response->success(['ignored' => true], 'Message payload unavailable');
        }
        $key = $message['key'] ?? [];
        $waId = $key['id'] ?? uniqid('in_', true);
        $existingMessage = Message::findByWhatsappId($instance->id, $waId);
        $type = self::extractMessageType($message);
        $text = self::extractMessageBody($message, $type);
        if ($existingMessage) {
            self::refreshStoredMessage($existingMessage, $message, $type, $text);
            if (is_array($body['media'] ?? null)) {
                self::attachMessageMedia((int) $existingMessage['id'], $body['media']);
            }
            return $response->success(['message_id' => (int) $existingMessage['id']], 'Message already stored');
        }

        $remoteJid = $key['remoteJid'] ?? ($body['from_jid'] ?? '');
        $direction = !empty($key['fromMe']) ? 'outbound' : 'inbound';
        $remoteLid = str_ends_with($remoteJid, '@lid') ? $remoteJid : null;
        $senderPn = !empty($key['senderPn']) ? (string) $key['senderPn'] : null;
        $ownJid = $instance->phone_number ? $instance->phone_number . '@s.whatsapp.net' : '';
        $fromJid = $direction === 'outbound' ? $ownJid : ($key['participant'] ?? $remoteJid);
        if ($remoteLid && $senderPn) {
            self::upsertContactIdentity($instance->id, $remoteLid, $senderPn);
        }
        if (str_ends_with($fromJid, '@lid') && $senderPn) {
            $fromJid = $senderPn;
            $remoteJid = $fromJid;
        }
        $toJid = $direction === 'outbound' ? $remoteJid : ($body['to_jid'] ?? $ownJid);
        $chatType = JidService::detectChatType($remoteJid ?: $fromJid);

        $chatId = self::upsertChat($instance->id, $remoteJid, $direction === 'inbound');
        if ($direction === 'inbound') {
            self::upsertContact($instance->id, $fromJid, $message['pushName'] ?? null);
        }

        $messageModel = new Message();
        $stored = $messageModel->create([
            'instance_id' => $instance->id,
            'chat_id' => $chatId,
            'whatsapp_message_id' => $waId,
            'direction' => $direction,
            'from_jid' => $fromJid,
            'to_jid' => $toJid,
            'chat_type' => $chatType,
            'message_type' => $type,
            'body' => $text,
            'raw_json' => $message,
            'status' => $direction === 'outbound' ? 'sent' : 'received',
            'sent_at' => $direction === 'outbound' ? date('Y-m-d H:i:s') : null,
            'received_at' => $direction === 'inbound' ? date('Y-m-d H:i:s') : null
        ]);

        if ($stored && is_array($body['media'] ?? null)) {
            self::attachMessageMedia((int) $stored->id, $body['media']);
        }

        WebhookDispatcher::dispatch($instance, $direction === 'outbound' ? 'message.sent' : 'message.received', [
            'message_id' => $stored->id,
            'whatsapp_message_id' => $waId,
            'from_jid' => $fromJid,
            'to_jid' => $toJid,
            'chat_type' => $chatType,
            'type' => $type,
            'body' => $text,
            'media' => ($body['media'] ?? null) ? [
                'url' => '/api/messages/' . $stored->id . '/media',
                'file_name' => $body['media']['file_name'] ?? null,
                'mime_type' => $body['media']['mime_type'] ?? null,
                'file_size' => $body['media']['file_size'] ?? null
            ] : null
        ]);

        return $response->success(['message_id' => $stored->id]);
    }

    public function media(Request $request, Response $response, string $id)
    {
        $stmt = App::$app->db->prepare('SELECT mm.* FROM message_media mm JOIN messages m ON m.id = mm.message_id JOIN instances i ON i.id = m.instance_id WHERE mm.message_id = :id AND i.user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $id, 'user_id' => (int) $request->getAttribute('api_user_id')]);
        $media = $stmt->fetch();
        if (!$media) return $response->error('Media not found', 404);
        $base = realpath(__DIR__ . '/../../storage/media');
        $file = $base ? realpath($base . DIRECTORY_SEPARATOR . $media['file_path']) : false;
        if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) return $response->error('Media file not found', 404);
        header('Content-Type: ' . ($media['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . basename($media['file_name'] ?: $file) . '"');
        readfile($file);
        exit;
    }

    public function internalMessageStatus(Request $request, Response $response)
    {
        $body = $request->getBody();
        $status = $body['status'] ?? null;
        if (!in_array($status, self::MESSAGE_STATUSES, true)) {
            return $response->error('Invalid status', 422);
        }

        $message = self::findMessageForStatus($body);
        if (!$message) {
            return $response->error('Message not found', 404);
        }

        $columns = ['status = :status', 'error_message = :error_message'];
        $params = [
            'id' => $message['id'],
            'status' => $status,
            'error_message' => $body['error_message'] ?? null
        ];

        if (!empty($body['whatsapp_message_id'])) {
            $columns[] = 'whatsapp_message_id = :whatsapp_message_id';
            $params['whatsapp_message_id'] = $body['whatsapp_message_id'];
        }

        $timeColumn = match ($status) {
            'sent' => 'sent_at',
            'delivered' => 'delivered_at',
            'read' => 'read_at',
            default => null
        };
        if ($timeColumn) {
            $columns[] = "{$timeColumn} = NOW()";
        }

        $stmt = App::$app->db->prepare("UPDATE messages SET " . implode(', ', $columns) . " WHERE id = :id");
        $stmt->execute($params);

        $instanceModel = new Instance();
        $instance = $instanceModel->findById($message['instance_id']);
        if ($instance) {
            $event = 'message.' . $status;
            WebhookDispatcher::dispatch($instance, $event, [
                'message_id' => (int) $message['id'],
                'whatsapp_message_id' => $body['whatsapp_message_id'] ?? $message['whatsapp_message_id'],
                'status' => $status,
                'error_message' => $body['error_message'] ?? null
            ]);
        }

        return $response->success();
    }

    public function internalContactsSync(Request $request, Response $response)
    {
        $body = $request->getBody();
        if (empty($body['instance_uuid']) || !is_array($body['contacts'] ?? null)) {
            return $response->error('Invalid payload', 422);
        }

        $instance = (new Instance())->findByUuid((string) $body['instance_uuid']);
        if (!$instance) return $response->error('Instance not found', 404);

        $synced = 0;
        foreach (array_slice($body['contacts'], 0, 500) as $contact) {
            if (!is_array($contact)) continue;
            $id = strtolower(trim((string) ($contact['id'] ?? '')));
            $lid = strtolower(trim((string) ($contact['lid'] ?? (str_ends_with($id, '@lid') ? $id : ''))));
            $phone = strtolower(trim((string) ($contact['jid'] ?? (str_ends_with($id, '@s.whatsapp.net') ? $id : ''))));
            if ($lid !== '' && !str_ends_with($lid, '@lid')) $lid = '';
            if ($phone !== '' && !str_ends_with($phone, '@s.whatsapp.net')) $phone = '';
            if ($lid !== '' || $phone !== '') {
                self::upsertContactIdentity($instance->id, $lid ?: null, $phone ?: null);
            }

            $canonical = $phone ?: ($lid ?: $id);
            if ($canonical !== '') {
                $name = trim((string) ($contact['name'] ?? $contact['notify'] ?? $contact['verifiedName'] ?? ''));
                self::upsertContact($instance->id, $canonical, $name ?: null);
                $synced++;
            }
        }

        return $response->success(['synced' => $synced]);
    }

    public function internalConnectionLog(Request $request, Response $response)
    {
        $body = $request->getBody();
        if (empty($body['instance_uuid']) || empty($body['event'])) {
            return $response->error('Invalid payload', 422);
        }

        $instanceModel = new Instance();
        $instance = $instanceModel->findByUuid($body['instance_uuid']);
        if (!$instance) {
            return $response->error('Instance not found', 404);
        }

        $event = self::connectionEventType($body['event']);
        Log::createConnectionLog($instance->id, $event, $body['description'] ?? '', $body['raw_json'] ?? $body);

        return $response->success();
    }

    private function findInstanceForApi(Request $request, string $uuid): ?Instance
    {
        $stmt = App::$app->db->prepare("SELECT * FROM instances WHERE uuid = :uuid AND user_id = :user_id LIMIT 1");
        $stmt->execute([
            'uuid' => $uuid,
            'user_id' => (int) $request->getAttribute('api_user_id')
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $instance = new Instance();
        $instance->load($row);
        return $instance;
    }

    private function callWorker(string $path, array $payload = []): array
    {
        $workerUrl = rtrim($_ENV['WORKER_URL'] ?? '', '/') . $path;
        $ch = curl_init($workerUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Worker-Secret: ' . ($_ENV['WORKER_SECRET'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'status' => $status, 'error' => $error ?: 'Worker unavailable'];
        }

        return json_decode($raw, true) ?: ['success' => $status >= 200 && $status < 300, 'raw' => $raw];
    }

    private static function normalizePhoneNumber(string $value): ?string
    {
        $number = preg_replace('/\D+/', '', $value);
        if (!$number || strlen($number) < 10 || strlen($number) > 15) {
            return null;
        }

        return $number;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function instancePayload(Instance $instance): array
    {
        return [
            'id' => $instance->id,
            'uuid' => $instance->uuid,
            'name' => $instance->name,
            'provider' => $instance->provider,
            'status' => $instance->status,
            'phone_number' => $instance->phone_number,
            'profile_name' => $instance->profile_name
        ];
    }

    private static function upsertChat(int $instanceId, string $jid, bool $incrementUnread = true): ?int
    {
        if ($jid === '') {
            return null;
        }

        $unreadIncrement = $incrementUnread ? 1 : 0;
        App::$app->db->prepare("
            INSERT INTO chats (instance_id, jid, unread_count)
            VALUES (:instance_id, :jid, :unread_increment)
            ON DUPLICATE KEY UPDATE unread_count = unread_count + :unread_update, updated_at = NOW()
        ")->execute(['instance_id' => $instanceId, 'jid' => $jid, 'unread_increment' => $unreadIncrement, 'unread_update' => $unreadIncrement]);

        $stmt = App::$app->db->prepare("SELECT id FROM chats WHERE instance_id = :instance_id AND jid = :jid LIMIT 1");
        $stmt->execute(['instance_id' => $instanceId, 'jid' => $jid]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    private static function upsertContact(int $instanceId, string $jid, ?string $pushName): void
    {
        if ($jid === '') {
            return;
        }

        App::$app->db->prepare("
            INSERT INTO contacts (instance_id, jid, push_name)
            VALUES (:instance_id, :jid, :push_name)
            ON DUPLICATE KEY UPDATE push_name = COALESCE(:push_name_update, push_name), updated_at = NOW()
        ")->execute([
            'instance_id' => $instanceId,
            'jid' => $jid,
            'push_name' => $pushName,
            'push_name_update' => $pushName
        ]);
    }

    private static function upsertContactIdentity(int $instanceId, ?string $lidJid, ?string $phoneJid): void
    {
        if (!$lidJid && !$phoneJid) return;

        $stmt = App::$app->db->prepare("
            INSERT INTO contact_identities (instance_id, lid_jid, phone_jid)
            VALUES (:instance_id, :lid_jid, :phone_jid)
            ON DUPLICATE KEY UPDATE
                lid_jid = COALESCE(VALUES(lid_jid), lid_jid),
                phone_jid = COALESCE(VALUES(phone_jid), phone_jid),
                updated_at = NOW()
        ");
        $stmt->execute([
            'instance_id' => $instanceId,
            'lid_jid' => $lidJid,
            'phone_jid' => $phoneJid,
        ]);
    }

    private static function extractMessageType(array $message): string
    {
        $payload = self::extractMessagePayload($message);
        return match (true) {
            isset($payload['conversation']), isset($payload['extendedTextMessage']) => 'text',
            isset($payload['imageMessage']) => 'image',
            isset($payload['audioMessage']) => 'audio',
            isset($payload['videoMessage']) => 'video',
            isset($payload['documentMessage']) => 'document',
            isset($payload['locationMessage']) => 'location',
            isset($payload['contactMessage']), isset($payload['contactsArrayMessage']) => 'contact',
            isset($payload['stickerMessage']) => 'sticker',
            isset($payload['reactionMessage']) => 'reaction',
            isset($payload['pollCreationMessage']), isset($payload['pollCreationMessageV2']), isset($payload['pollCreationMessageV3']) => 'poll',
            isset($payload['protocolMessage']) && isset($payload['protocolMessage']['key']) => 'deleted',
            isset($payload['buttonsMessage']), isset($payload['buttonsResponseMessage']) => 'buttons',
            isset($payload['listMessage']), isset($payload['listResponseMessage']) => 'list',
            isset($payload['interactiveMessage']), isset($payload['interactiveResponseMessage']) => 'interactive',
            default => 'unknown',
        };
    }

    private static function extractMessageBody(array $message, string $type): string
    {
        $payload = self::extractMessagePayload($message);
        return match ($type) {
            'text' => $payload['conversation'] ?? ($payload['extendedTextMessage']['text'] ?? ''),
            'image' => $payload['imageMessage']['caption'] ?? '',
            'video' => $payload['videoMessage']['caption'] ?? '',
            'document' => $payload['documentMessage']['caption'] ?? ($payload['documentMessage']['fileName'] ?? ''),
            'reaction' => $payload['reactionMessage']['text'] ?? '',
            'location' => json_encode($payload['locationMessage'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'poll' => $payload['pollCreationMessage']['name'] ?? $payload['pollCreationMessageV2']['name'] ?? $payload['pollCreationMessageV3']['name'] ?? '',
            'deleted' => 'Mensagem apagada',
            'buttons' => $payload['buttonsResponseMessage']['selectedDisplayText'] ?? $payload['buttonsMessage']['contentText'] ?? '',
            'list' => $payload['listResponseMessage']['title'] ?? $payload['listMessage']['description'] ?? '',
            'interactive' => $payload['interactiveMessage']['body']['text'] ?? '',
            default => '',
        };
    }

    private static function extractMessagePayload(array $message): array
    {
        $payload = $message['message'] ?? [];
        if (!is_array($payload)) {
            return [];
        }

        $wrappers = [
            'ephemeralMessage',
            'viewOnceMessage',
            'documentWithCaptionMessage',
            'viewOnceMessageV2',
            'viewOnceMessageV2Extension',
            'editedMessage',
        ];

        for ($i = 0; $i < 5; $i++) {
            foreach ($wrappers as $wrapper) {
                if (isset($payload[$wrapper]['message']) && is_array($payload[$wrapper]['message'])) {
                    $payload = $payload[$wrapper]['message'];
                    continue 2;
                }
            }
            break;
        }

        return $payload;
    }

    private static function refreshStoredMessage(array $existing, array $message, string $type, string $text): void
    {
        $sets = [];
        $params = ['id' => (int) $existing['id']];

        if (($existing['message_type'] ?? 'unknown') === 'unknown' && $type !== 'unknown') {
            $sets[] = 'message_type = :message_type';
            $params['message_type'] = $type;
        }

        if (trim((string) ($existing['body'] ?? '')) === '' && $text !== '') {
            $sets[] = 'body = :body';
            $params['body'] = $text;
        }

        if ($sets) {
            $rawJson = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($rawJson !== false) {
                $sets[] = 'raw_json = :raw_json';
                $params['raw_json'] = $rawJson;
            }
        }

        if (!$sets) {
            return;
        }

        $stmt = App::$app->db->prepare('UPDATE messages SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    private static function attachMessageMedia(int $messageId, array $media): bool
    {
        $exists = App::$app->db->prepare('SELECT id FROM message_media WHERE message_id = :message_id LIMIT 1');
        $exists->execute(['message_id' => $messageId]);
        if ($exists->fetchColumn()) {
            return false;
        }

        $filePath = self::normalizeMediaPath($media['file_path'] ?? null);
        if (!$filePath) {
            return false;
        }

        $stmt = App::$app->db->prepare("
            INSERT INTO message_media (message_id, file_path, file_name, mime_type, file_size)
            VALUES (:message_id, :file_path, :file_name, :mime_type, :file_size)
        ");
        $stmt->execute([
            'message_id' => $messageId,
            'file_path' => $filePath,
            'file_name' => $media['file_name'] ?? null,
            'mime_type' => $media['mime_type'] ?? null,
            'file_size' => isset($media['file_size']) ? (int) $media['file_size'] : null
        ]);

        return true;
    }

    private static function normalizeMediaPath(mixed $value): ?string
    {
        $filePath = str_replace('\\', '/', trim((string) $value));
        if (!preg_match('#^[a-f0-9-]+/\d{4}-\d{2}/[a-f0-9-]+\.[a-z0-9]+$#i', $filePath)) {
            return null;
        }

        return $filePath;
    }

    private static function findMessageForStatus(array $body): ?array
    {
        if (!empty($body['message_id'])) {
            $stmt = App::$app->db->prepare("SELECT * FROM messages WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $body['message_id']]);
            $row = $stmt->fetch();
            return $row ?: null;
        }

        if (!empty($body['instance_uuid']) && !empty($body['whatsapp_message_id'])) {
            $stmt = App::$app->db->prepare("
                SELECT m.*
                FROM messages m
                JOIN instances i ON i.id = m.instance_id
                WHERE i.uuid = :uuid AND m.whatsapp_message_id = :message_id
                LIMIT 1
            ");
            $stmt->execute([
                'uuid' => $body['instance_uuid'],
                'message_id' => $body['whatsapp_message_id']
            ]);
            $row = $stmt->fetch();
            return $row ?: null;
        }

        return null;
    }

    private static function connectionEventType(string $statusOrEvent): string
    {
        return match ($statusOrEvent) {
            'waiting_qr', 'qr', 'instance.qr' => 'qr_generated',
            'connected', 'instance.connected' => 'connected',
            'disconnected', 'instance.disconnected' => 'disconnected',
            'logged_out', 'instance.logged_out' => 'logged_out',
            'reconnecting' => 'reconnecting',
            'error' => 'error',
            default => 'connecting',
        };
    }

    private static function instanceWebhookEvents(?string $status, ?string $qr): array
    {
        $events = [];
        if ($qr !== null) {
            $events[] = 'instance.qr';
        }
        if ($status === 'connected') {
            $events[] = 'instance.connected';
        }
        if ($status === 'disconnected') {
            $events[] = 'instance.disconnected';
        }
        if ($status === 'logged_out') {
            $events[] = 'instance.logged_out';
        }

        return array_values(array_unique($events));
    }
}
