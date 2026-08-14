<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\App;
use App\Core\Auth;
use App\Models\Instance;
use App\Models\Webhook;

class WebhookController extends Controller
{
    public function index(Request $request, Response $response)
    {
        return $response->redirect('/instances');
    }

    public function store(Request $request, Response $response)
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

        $stmt = App::$app->db->prepare("SELECT * FROM webhooks WHERE instance_id = :id ORDER BY id DESC");
        $stmt->execute(['id' => $instance->id]);
        $webhooks = $stmt->fetchAll();

        $logsStmt = App::$app->db->prepare("
            SELECT wl.*, w.name AS webhook_name
            FROM webhook_logs wl
            JOIN webhooks w ON w.id = wl.webhook_id
            WHERE wl.instance_id = :id
            ORDER BY wl.id DESC
            LIMIT 50
        ");
        $logsStmt->execute(['id' => $instance->id]);
        $webhookLogs = $logsStmt->fetchAll();

        $view = 'webhooks/instance';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function storeForInstance(Request $request, Response $response, string $id)
    {
        $body = $request->getBody();
        $instanceModel = new Instance();
        $instance = $instanceModel->findByIdForUser($id, (int) Auth::user()->id);

        if (!$instance) {
            return $response->redirect('/instances');
        }

        $events = $body['events'] ?? [];
        if (is_string($events)) {
            $events = array_values(array_filter(array_map('trim', explode(',', $events))));
        }

        $name = trim($body['name'] ?? '');
        $url = trim($body['url'] ?? '');
        if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL) || !$events) {
            return $response->redirect("/instances/{$instance->id}/webhooks");
        }

        Webhook::create([
            'instance_id' => $instance->id,
            'name' => $name,
            'url' => $url,
            'secret' => ($body['secret'] ?? '') ?: bin2hex(random_bytes(24)),
            'events' => $events,
            'active' => !empty($body['active'])
        ]);

        return $response->redirect("/instances/{$instance->id}/webhooks");
    }
}
