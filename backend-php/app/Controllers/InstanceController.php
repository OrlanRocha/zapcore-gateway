<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Instance;
use App\Models\Log;
use App\Models\RecipientConsent;
use App\Models\User;
use App\Core\App;
use App\Services\QueueService;
use App\Services\JidService;
use App\Services\WebhookDispatcher;

class InstanceController extends Controller
{
    public function index(Request $request, Response $response)
    {
        $instances = Instance::allByUser((int) Auth::user()->id);
        $view = 'instances/index';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function create(Request $request, Response $response)
    {
        $view = 'instances/create';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function store(Request $request, Response $response)
    {
        $body = $request->getBody();
        $name = $body['name'] ?? 'Unnamed Instance';

        $uuid = self::uuid();

        $instanceModel = new Instance();
        $instanceModel->create([
            'user_id' => Auth::user()->id,
            'uuid' => $uuid,
            'name' => $name,
            'provider' => 'baileys'
        ]);

        return $response->redirect('/instances');
    }

    public function show(Request $request, Response $response, string $id)
    {
        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);

        if (!$instance) {
            return $response->redirect('/instances');
        }

        $logsStmt = App::$app->db->prepare("SELECT * FROM connection_logs WHERE instance_id = :id ORDER BY id DESC LIMIT 20");
        $logsStmt->execute(['id' => $instance->id]);
        $connectionLogs = $logsStmt->fetchAll();

        $messageStatsStmt = App::$app->db->prepare("
            SELECT
                SUM(direction = 'outbound') AS sent_by_app_count,
                SUM(direction = 'inbound') AS received_count,
                SUM(status = 'failed') AS failed_count
            FROM messages
            WHERE instance_id = :id
        ");
        $messageStatsStmt->execute(['id' => $instance->id]);
        $messageStats = $messageStatsStmt->fetch() ?: [];

        $messageBreakdownStmt = App::$app->db->prepare("
            SELECT
                COALESCE(chat_type, 'unknown') AS chat_type,
                SUM(direction = 'outbound') AS sent_by_app_count,
                SUM(direction = 'inbound') AS received_count,
                SUM(status = 'failed') AS failed_count
            FROM messages
            WHERE instance_id = :id
            GROUP BY COALESCE(chat_type, 'unknown')
        ");
        $messageBreakdownStmt->execute(['id' => $instance->id]);
        $messageBreakdown = self::emptyMessageBreakdown();
        foreach ($messageBreakdownStmt->fetchAll() as $row) {
            $type = self::normalizeChatType($row['chat_type'] ?? 'unknown');
            $messageBreakdown['sent'][$type] = (int) ($row['sent_by_app_count'] ?? 0);
            $messageBreakdown['received'][$type] = (int) ($row['received_count'] ?? 0);
            $messageBreakdown['failed'][$type] = (int) ($row['failed_count'] ?? 0);
        }

        $sentByAppCount = (int) ($messageStats['sent_by_app_count'] ?? 0);
        $receivedCount = (int) ($messageStats['received_count'] ?? 0);
        $failedCount = (int) ($messageStats['failed_count'] ?? 0);
        $webhookCount = (int) self::fetchColumn(
            "SELECT COUNT(*) FROM webhooks WHERE instance_id = :id",
            ['id' => $instance->id]
        );

        $latestMessagesStmt = App::$app->db->prepare("
            SELECT * FROM messages
            WHERE instance_id = :id
            ORDER BY id DESC
            LIMIT 8
        ");
        $latestMessagesStmt->execute(['id' => $instance->id]);
        $latestMessages = $latestMessagesStmt->fetchAll();

        $webhooksStmt = App::$app->db->prepare("SELECT * FROM webhooks WHERE instance_id = :id ORDER BY id DESC LIMIT 8");
        $webhooksStmt->execute(['id' => $instance->id]);
        $instanceWebhooks = $webhooksStmt->fetchAll();
        $canManageShares = $instance->access_role === 'owner';
        $instanceShares = $canManageShares ? Instance::shares($instance->id) : [];
        $recipientConsents = $canManageShares ? RecipientConsent::listForInstance($instance->id) : [];

        $view = 'instances/show';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function qr(Request $request, Response $response, string $id)
    {
        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);

        if (!$instance) {
            return $response->json(['error' => 'Instance not found'], 404);
        }

        return $response->json(['qr' => $instance->qr_code, 'status' => $instance->status]);
    }
    
    public function connect(Request $request, Response $response, string $id)
    {
        $body = $request->getBody();
        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);
        
        if (!$instance) {
             return $response->json(['error' => 'Instance not found'], 404);
        }

        $mode = (($body['mode'] ?? 'qr') === 'pin') ? 'pin' : 'qr';
        $payload = ['mode' => $mode];
        $phoneNumber = null;

        if ($mode === 'pin') {
            $phoneNumber = self::normalizePhoneNumber((string) ($body['phone_number'] ?? $body['phoneNumber'] ?? ''));
            if (!$phoneNumber) {
                return $response->json(['success' => false, 'error' => 'Informe um numero valido com DDI para gerar o PIN Code'], 422);
            }

            $payload['phone_number'] = $phoneNumber;
        }
        
        $result = $this->callWorker("/worker/instances/{$instance->uuid}/connect", $payload);

        if (!$result['success']) {
            $instance->updateStatus('error');
            Log::createConnectionLog($instance->id, 'error', $result['error'] ?? 'Worker connection failed');
            return $response->json(['success' => false, 'error' => $result['error'] ?? 'Worker connection failed'], 502);
        }

        $workerData = $result['data']['data'] ?? $result['data'] ?? [];
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
                ? 'Connection requested from web panel; instance was already connected'
                : ($mode === 'pin' ? 'PIN Code connection requested from web panel' : 'QR Code connection requested from web panel')
        );
        
        return $response->json([
            'success' => true,
            'data' => [
                'mode' => $mode,
                'pairing_code' => $workerData['pairing_code'] ?? null,
                'already_connected' => (bool) ($workerData['already_connected'] ?? false)
            ],
            'worker_response' => $result['data']
        ]);
    }

    public function disconnect(Request $request, Response $response, string $id)
    {
        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);
        
        if (!$instance) {
             return $response->json(['error' => 'Instance not found'], 404);
        }
        
        $result = $this->callWorker("/worker/instances/{$instance->uuid}/disconnect");
        
        $instance->update([
            'status' => 'disconnected',
            'last_disconnected_at' => date('Y-m-d H:i:s')
        ]);
        Log::createConnectionLog($instance->id, 'disconnected', 'Disconnect requested from web panel');
        WebhookDispatcher::dispatch($instance, 'instance.disconnected', ['reason' => 'web_panel']);
        
        return $response->json(['success' => true, 'worker_response' => $result['data'] ?? null]);
    }

    public function destroy(Request $request, Response $response, string $id)
    {
        $instance = Instance::findOwnedById((int) $id, (int) Auth::user()->id);

        if (!$instance) {
            return $response->json(['success' => false, 'error' => 'Instance not found'], 404);
        }

        if ($instance->status === 'connected' || $instance->status === 'connecting' || $instance->status === 'waiting_qr') {
            $this->callWorker("/worker/instances/{$instance->uuid}/disconnect");
        }

        if (!Instance::deleteForUser($instance->id, (int) Auth::user()->id)) {
            return $response->json(['success' => false, 'error' => 'Instance not found'], 404);
        }

        return $response->json([
            'success' => true,
            'message' => 'Instancia excluida com sucesso',
            'redirect' => '/instances'
        ]);
    }

    public function sendTest(Request $request, Response $response, string $id)
    {
        $body = $request->getBody();
        $to = $body['to'] ?? '';
        $text = $body['message'] ?? '';
        $chatType = $body['chat_type'] ?? null;

        if (!$to || !$text) {
            return $response->json(['success' => false, 'error' => 'Missing to or message']);
        }

        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);

        if (!$instance) {
            return $response->json(['success' => false, 'error' => 'Instance not found']);
        }

        if ($instance->status !== 'connected') {
            return $response->json(['success' => false, 'error' => 'Instance not connected']);
        }

        try {
            $queued = QueueService::enqueueTextTo($instance, $to, $text, $chatType);
            return $response->json([
                'success' => true,
                'data' => [
                    'message_id' => $queued['message']->id,
                    'queue_id' => $queued['queue']->id,
                    'chat_type' => $queued['chat_type'],
                    'to_jid' => $queued['to_jid'],
                    'scheduled_at' => $queued['scheduled_at']
                ]
            ]);
        } catch (\Throwable $e) {
            return $response->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function share(Request $request, Response $response, string $id)
    {
        $ownerId = (int) Auth::user()->id;
        $instance = Instance::findOwnedById((int) $id, $ownerId);
        if (!$instance) return $response->json(['success' => false, 'error' => 'Instance not found'], 404);

        $identity = trim((string) ($request->getBody()['identity'] ?? ''));
        $matches = User::findActiveByIdentity($identity);
        if (!$matches) return $response->json(['success' => false, 'error' => 'Usuario ativo nao encontrado'], 404);
        if (count($matches) > 1) return $response->json(['success' => false, 'error' => 'Login ambiguo. Informe o e-mail do usuario.'], 422);

        $target = $matches[0];
        if ((int) $target['id'] === $ownerId) {
            return $response->json(['success' => false, 'error' => 'A instancia ja pertence a este usuario'], 422);
        }
        if (!Instance::shareWithUser($instance->id, $ownerId, (int) $target['id'])) {
            return $response->json(['success' => false, 'error' => 'A instancia ja esta compartilhada com este usuario'], 409);
        }

        return $response->json(['success' => true, 'user' => $target]);
    }

    public function revokeShare(Request $request, Response $response, string $id)
    {
        $ownerId = (int) Auth::user()->id;
        $userId = (int) ($request->getBody()['user_id'] ?? 0);
        if ($userId <= 0 || !Instance::revokeShare((int) $id, $ownerId, $userId)) {
            return $response->json(['success' => false, 'error' => 'Compartilhamento nao encontrado'], 404);
        }
        return $response->json(['success' => true]);
    }

    public function grantConsent(Request $request, Response $response, string $id)
    {
        $ownerId = (int) Auth::user()->id;
        $instance = Instance::findOwnedById((int) $id, $ownerId);
        if (!$instance) return $response->json(['success' => false, 'error' => 'Instance not found'], 404);

        try {
            $body = $request->getBody();
            $jid = JidService::normalize((string) ($body['to'] ?? ''), 'user')['jid'];
            RecipientConsent::grant($instance->id, $jid, 'manual', $ownerId, $body['note'] ?? null);
            return $response->json(['success' => true, 'jid' => $jid]);
        } catch (\InvalidArgumentException $e) {
            return $response->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function revokeConsent(Request $request, Response $response, string $id)
    {
        $ownerId = (int) Auth::user()->id;
        $instance = Instance::findOwnedById((int) $id, $ownerId);
        if (!$instance) return $response->json(['success' => false, 'error' => 'Instance not found'], 404);

        try {
            $jid = JidService::normalize((string) ($request->getBody()['to'] ?? ''), 'user')['jid'];
            RecipientConsent::revoke($instance->id, $jid, 'manual', 'Revogado pelo proprietario da instancia');
            return $response->json(['success' => true, 'jid' => $jid]);
        } catch (\InvalidArgumentException $e) {
            return $response->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = App::$app->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private static function normalizePhoneNumber(string $value): ?string
    {
        $number = preg_replace('/\D+/', '', $value);
        if (!$number || strlen($number) < 10 || strlen($number) > 15) {
            return null;
        }

        return $number;
    }

    private static function emptyMessageBreakdown(): array
    {
        return [
            'sent' => self::zeroChatTypes(),
            'received' => self::zeroChatTypes(),
            'failed' => self::zeroChatTypes(),
        ];
    }

    private static function zeroChatTypes(): array
    {
        return [
            'user' => 0,
            'group' => 0,
            'newsletter' => 0,
            'unknown' => 0,
        ];
    }

    private static function normalizeChatType(string $type): string
    {
        return in_array($type, ['user', 'group', 'newsletter'], true) ? $type : 'unknown';
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
            return ['success' => false, 'error' => $error ?: 'Worker unavailable', 'status' => $status];
        }

        $decoded = json_decode($raw, true);
        $success = $status >= 200 && $status < 300 && (($decoded['success'] ?? true) !== false);

        return [
            'success' => $success,
            'status' => $status,
            'data' => $decoded,
            'error' => $decoded['error'] ?? ($success ? null : 'Worker returned an error')
        ];
    }
}
