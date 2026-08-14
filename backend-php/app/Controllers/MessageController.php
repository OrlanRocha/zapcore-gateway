<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\App;
use App\Core\Auth;
use App\Models\Instance;
use App\Models\Message;
use App\Services\JidService;
use App\Services\QueueService;

class MessageController extends Controller
{
    public function index(Request $request, Response $response)
    {
        return $response->redirect('/instances');
    }

    public function instance(Request $request, Response $response, string $id)
    {
        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);

        if (!$instance) {
            return $response->redirect('/instances');
        }

        $query = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $status = trim($_GET['status'] ?? '');
        $direction = trim($_GET['direction'] ?? '');
        $chatType = trim($_GET['chat_type'] ?? '');
        $page = max((int) ($_GET['page'] ?? 1), 1);
        $perPage = min(max((int) ($_GET['per_page'] ?? 15), 10), 100);
        $offset = ($page - 1) * $perPage;

        $where = ['instance_id = :instance_id'];
        $params = ['instance_id' => $instance->id];

        if ($query !== '') {
            $search = '%' . $query . '%';
            $where[] = "(body LIKE :q_body OR from_jid LIKE :q_from OR to_jid LIKE :q_to OR whatsapp_message_id LIKE :q_wa_id)";
            $params['q_body'] = $search;
            $params['q_from'] = $search;
            $params['q_to'] = $search;
            $params['q_wa_id'] = $search;
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if (in_array($direction, ['inbound', 'outbound'], true)) {
            $where[] = 'direction = :direction';
            $params['direction'] = $direction;
        }
        if (in_array($chatType, ['user', 'group', 'newsletter'], true)) {
            $where[] = 'chat_type = :chat_type';
            $params['chat_type'] = $chatType;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = App::$app->db->prepare("SELECT COUNT(*) FROM messages WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max((int) ceil($total / $perPage), 1);

        $stmt = App::$app->db->prepare("
            SELECT
                m.id,
                m.direction,
                m.from_jid,
                m.to_jid,
                m.chat_type,
                m.whatsapp_message_id,
                m.message_type,
                m.body,
                m.status,
                m.created_at,
                mm.mime_type AS media_mime_type,
                mm.file_name AS media_file_name
            FROM messages m
            LEFT JOIN message_media mm ON mm.message_id = m.id
            WHERE {$whereSql}
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll();

        $view = 'messages/instance';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function media(Request $request, Response $response, string $id)
    {
        $stmt = App::$app->db->prepare('SELECT mm.* FROM message_media mm JOIN messages m ON m.id = mm.message_id JOIN instances i ON i.id = m.instance_id WHERE mm.message_id = :id AND (i.user_id = :owner_user_id OR EXISTS (SELECT 1 FROM instance_shares ish WHERE ish.instance_id = i.id AND ish.user_id = :shared_user_id)) LIMIT 1');
        $userId = (int) Auth::user()->id;
        $stmt->execute(['id' => $id, 'owner_user_id' => $userId, 'shared_user_id' => $userId]);
        $media = $stmt->fetch();
        if (!$media) { $response->setStatusCode(404); exit('Media not found'); }
        $base = realpath(__DIR__ . '/../../storage/media');
        $file = $base ? realpath($base . DIRECTORY_SEPARATOR . $media['file_path']) : false;
        if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) { $response->setStatusCode(404); exit('Media file not found'); }
        header('Content-Type: ' . ($media['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . basename($media['file_name'] ?: $file) . '"');
        readfile($file);
        exit;
    }

    public function chat(Request $request, Response $response, string $id)
    {
        $instance = (new Instance())->findByIdForUser($id, (int) Auth::user()->id);
        if (!$instance) return $response->error('Instance not found', 404);
        $page = max((int) ($_GET['page'] ?? 1), 1);
        $limit = min(max((int) ($_GET['limit'] ?? 30), 10), 100);
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $type = trim((string) ($_GET['chat_type'] ?? ''));
        $jid = trim((string) ($_GET['jid'] ?? ''));
        $where = [
            'm.instance_id = :instance_id',
            "COALESCE(m.raw_json, '') NOT LIKE '%\"Message absent from node\"%'"
        ];
        $params = ['instance_id' => $instance->id];
        if ($q !== '') {
            $where[] = '(m.body LIKE :q_body OR m.from_jid LIKE :q_from OR m.to_jid LIKE :q_to OR c.jid LIKE :q_chat)';
            $params['q_body'] = "%{$q}%";
            $params['q_from'] = "%{$q}%";
            $params['q_to'] = "%{$q}%";
            $params['q_chat'] = "%{$q}%";
        }
        if (in_array($type, ['user', 'group', 'newsletter'], true)) { $where[] = 'm.chat_type = :chat_type'; $params['chat_type'] = $type; }
        if ($jid !== '') {
            $where[] = "(c.jid = :chat_jid OR ci.lid_jid = :chat_lid OR ci.phone_jid = :chat_phone OR (c.id IS NULL AND ((m.direction = 'inbound' AND m.from_jid = :chat_jid_in) OR (m.direction = 'outbound' AND m.to_jid = :chat_jid_out))))";
            $params['chat_jid'] = $jid;
            $params['chat_lid'] = $jid;
            $params['chat_phone'] = $jid;
            $params['chat_jid_in'] = $jid;
            $params['chat_jid_out'] = $jid;
        }
        $whereSql = implode(' AND ', $where);
        $count = App::$app->db->prepare("SELECT COUNT(*) FROM messages m LEFT JOIN chats c ON c.id = m.chat_id LEFT JOIN contact_identities ci ON ci.instance_id = m.instance_id AND (ci.lid_jid = c.jid OR ci.phone_jid = c.jid) WHERE {$whereSql}"); $count->execute($params);
        $total = (int) $count->fetchColumn(); $offset = ($page - 1) * $limit;
        $stmt = App::$app->db->prepare("SELECT m.id, m.direction, m.from_jid, m.to_jid, m.chat_type, m.message_type, m.body, m.status, m.created_at, m.raw_json, mm.file_name, mm.mime_type, CASE WHEN mm.id IS NOT NULL THEN CONCAT('/messages/', m.id, '/media') WHEN m.direction = 'outbound' AND m.message_type IN ('image','audio','video','document') AND m.body LIKE 'http%' THEN m.body ELSE NULL END AS media_url FROM messages m LEFT JOIN chats c ON c.id = m.chat_id LEFT JOIN contact_identities ci ON ci.instance_id = m.instance_id AND (ci.lid_jid = c.jid OR ci.phone_jid = c.jid) LEFT JOIN message_media mm ON mm.message_id = m.id WHERE {$whereSql} ORDER BY m.id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT); $stmt->bindValue('offset', $offset, \PDO::PARAM_INT); $stmt->execute();
        $messages = array_map(static function (array $row): array {
            $raw = json_decode($row['raw_json'] ?? '', true);
            $payload = self::extractMessagePayload(is_array($raw) ? $raw : []);
            $payloadType = self::messageTypeFromPayload($payload);

            if (($row['message_type'] ?? 'unknown') === 'unknown' && $payloadType !== 'unknown') {
                $row['message_type'] = $payloadType;
            }

            $thumbnail = self::thumbnailDataUri($payload);
            if (!$row['media_url'] && $thumbnail) {
                $row['media_url'] = $thumbnail;
            }

            if (!$row['mime_type'] && $row['message_type'] === 'image') $row['mime_type'] = 'image/*';
            if (!$row['mime_type'] && $row['message_type'] === 'video') $row['mime_type'] = 'video/*';
            if (!$row['mime_type'] && $row['message_type'] === 'audio') $row['mime_type'] = 'audio/*';

            unset($row['raw_json']);
            return $row;
        }, $stmt->fetchAll());
        return $response->success(['messages' => array_reverse($messages), 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => max((int) ceil($total / $limit), 1)]]);
    }

    public function contacts(Request $request, Response $response, string $id)
    {
        $instance = (new Instance())->findByIdForUser($id, (int) Auth::user()->id);
        if (!$instance) return $response->error('Instance not found', 404);
        $type = trim((string) ($_GET['chat_type'] ?? 'user'));
        $q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $page = max((int) ($_GET['page'] ?? 1), 1);
        $limit = min(max((int) ($_GET['limit'] ?? 100), 10), 100);
        $stmt = App::$app->db->prepare("
            SELECT
                COALESCE(ci.phone_jid, c.jid) AS jid,
                c.unread_count,
                COALESCE(c.name, ct.name, ct.push_name, ct.short_name) AS display_name,
                m.message_type,
                m.body,
                m.created_at AS last_at
            FROM chats c
            LEFT JOIN contact_identities ci ON ci.instance_id = c.instance_id AND (ci.lid_jid = c.jid OR ci.phone_jid = c.jid)
            LEFT JOIN contacts ct ON ct.instance_id = c.instance_id AND ct.jid = COALESCE(ci.phone_jid, c.jid)
            LEFT JOIN messages m ON m.id = (
                SELECT m2.id
                FROM messages m2
                WHERE m2.chat_id = c.id
                  AND COALESCE(m2.raw_json, '') NOT LIKE '%\"Message absent from node\"%'
                ORDER BY m2.id DESC
                LIMIT 1
            )
            WHERE c.instance_id = :instance_id
            ORDER BY COALESCE(m.created_at, c.updated_at) DESC
            LIMIT 500
        ");
        $stmt->execute(['instance_id' => $instance->id]);

        $contacts = [];
        foreach ($stmt->fetchAll() as $row) {
            $chatType = JidService::detectChatType((string) $row['jid']);
            if (in_array($type, ['user', 'group', 'newsletter'], true) && $chatType !== $type) continue;

            $searchable = implode(' ', [$row['jid'], $row['display_name'] ?? '', $row['body'] ?? '']);
            if ($q !== '' && stripos($searchable, $q) === false) continue;

            $contacts[] = [
                'jid' => $row['jid'],
                'name' => $row['display_name'],
                'chat_type' => $chatType,
                'last_message' => $row['body'] ?: ($row['message_type'] ? ucfirst($row['message_type']) : ''),
                'last_at' => $row['last_at'],
                'unread' => (int) $row['unread_count'],
            ];
        }

        $total = count($contacts);
        $contacts = array_slice($contacts, ($page - 1) * $limit, $limit);
        return $response->success([
            'contacts' => $contacts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max((int) ceil($total / $limit), 1),
            ],
        ]);
    }

    public function markRead(Request $request, Response $response, string $id)
    {
        $instance = (new Instance())->findByIdForUser($id, (int) Auth::user()->id);
        if (!$instance) return $response->error('Instance not found', 404);

        $jid = trim((string) ($request->getBody()['jid'] ?? ''));
        if ($jid === '') return $response->error('jid is required', 422);

        $chat = App::$app->db->prepare('SELECT c.id FROM chats c LEFT JOIN contact_identities ci ON ci.instance_id = c.instance_id AND (ci.lid_jid = c.jid OR ci.phone_jid = c.jid) WHERE c.instance_id = :instance_id AND (c.jid = :jid_direct OR ci.lid_jid = :jid_lid OR ci.phone_jid = :jid_phone) LIMIT 1');
        $chat->execute(['instance_id' => $instance->id, 'jid_direct' => $jid, 'jid_lid' => $jid, 'jid_phone' => $jid]);
        $chatId = $chat->fetchColumn();
        if (!$chatId) return $response->error('Conversation not found', 404);

        App::$app->db->prepare("UPDATE messages SET status = 'read', read_at = COALESCE(read_at, NOW()) WHERE instance_id = :instance_id AND chat_id = :chat_id AND direction = 'inbound' AND status = 'received'")
            ->execute(['instance_id' => $instance->id, 'chat_id' => $chatId]);
        App::$app->db->prepare('UPDATE chats SET unread_count = 0 WHERE id = :chat_id AND instance_id = :instance_id')
            ->execute(['chat_id' => $chatId, 'instance_id' => $instance->id]);

        return $response->success();
    }

    public function sendChat(Request $request, Response $response, string $id)
    {
        $instance = (new Instance())->findByIdForUser($id, (int) Auth::user()->id); $body = $request->getBody();
        if (!$instance) return $response->error('Instance not found', 404);
        if ($instance->status !== 'connected') return $response->error('Instance not connected', 422);
        $to = trim((string) ($body['to'] ?? '')); $chatType = $body['chat_type'] ?? 'user';
        try {
            if (!empty($body['media_url'])) $queued = QueueService::enqueueMediaTo($instance, $to, (string) ($body['media_type'] ?? 'image'), (string) $body['media_url'], $body['caption'] ?? null, $body['file_name'] ?? null, $chatType);
            else $queued = QueueService::enqueueTextTo($instance, $to, trim((string) ($body['text'] ?? '')), $chatType);
            return $response->success($queued, 'Mensagem adicionada a fila');
        } catch (\Throwable $e) { return $response->error($e->getMessage(), 422); }
    }

    private static function extractMessagePayload(array $raw): array
    {
        $payload = $raw['message'] ?? [];
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

    private static function messageTypeFromPayload(array $payload): string
    {
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

    private static function thumbnailDataUri(array $payload): ?string
    {
        $thumbnail = $payload['imageMessage']['jpegThumbnail'] ?? $payload['stickerMessage']['jpegThumbnail'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            return str_starts_with($thumbnail, 'data:')
                ? $thumbnail
                : 'data:image/jpeg;base64,' . $thumbnail;
        }

        if (is_array($thumbnail) && ($thumbnail['type'] ?? '') === 'Buffer' && is_array($thumbnail['data'] ?? null)) {
            $bytes = array_map(static fn($byte) => max(0, min(255, (int) $byte)), $thumbnail['data']);
            return 'data:image/jpeg;base64,' . base64_encode(pack('C*', ...$bytes));
        }

        return null;
    }
}
