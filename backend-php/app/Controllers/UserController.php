<?php

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class UserController extends Controller
{
    private array $roles = [
        'admin' => 'Administrador',
        'user' => 'Usuario'
    ];

    public function index(Request $request, Response $response)
    {
        $users = User::all();
        $roles = $this->roles;
        $view = 'users/index';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function create(Request $request, Response $response)
    {
        $roles = $this->roles;
        $user = null;
        $errors = [];
        $view = 'users/form';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function store(Request $request, Response $response)
    {
        $body = $request->getBody();
        $errors = $this->validate($body, null, true);

        if ($errors) {
            $roles = $this->roles;
            $user = null;
            $old = $body;
            $view = 'users/form';
            ob_start();
            include __DIR__ . '/../Views/layouts/app.php';
            return ob_get_clean();
        }

        User::create([
            'name' => trim($body['name']),
            'email' => strtolower(trim($body['email'])),
            'password' => $body['password'],
            'role' => $body['role'] ?? 'user',
            'active' => !empty($body['active'])
        ]);

        return $response->redirect('/users');
    }

    public function edit(Request $request, Response $response, string $id)
    {
        $userModel = new User();
        $user = $userModel->findById($id);
        if (!$user) {
            return $response->redirect('/users');
        }

        $roles = $this->roles;
        $errors = [];
        $view = 'users/form';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function update(Request $request, Response $response, string $id)
    {
        $userModel = new User();
        $user = $userModel->findById($id);
        if (!$user) {
            return $response->redirect('/users');
        }

        $body = $request->getBody();
        $errors = $this->validate($body, $user->id, false);
        $currentUser = Auth::user();
        $newRole = User::validRole($body['role'] ?? $user->role);
        $newActive = !empty($body['active']) ? 1 : 0;

        if ($currentUser && $currentUser->id === $user->id) {
            $newRole = $user->role;
            $newActive = 1;
        }

        if ($this->wouldRemoveLastAdmin($user, $newRole, $newActive)) {
            $errors[] = 'Nao e possivel remover o ultimo administrador ativo.';
        }

        if ($errors) {
            $roles = $this->roles;
            $old = $body;
            $view = 'users/form';
            ob_start();
            include __DIR__ . '/../Views/layouts/app.php';
            return ob_get_clean();
        }

        $data = [
            'name' => trim($body['name']),
            'email' => strtolower(trim($body['email'])),
            'role' => $newRole,
            'active' => $newActive
        ];
        if (!empty($body['password'])) {
            $data['password'] = $body['password'];
        }

        $user->update($data);
        return $response->redirect('/users');
    }

    public function destroy(Request $request, Response $response, string $id)
    {
        $userModel = new User();
        $user = $userModel->findById($id);
        $currentUser = Auth::user();

        if (!$user) {
            return $response->json(['success' => false, 'error' => 'Usuario nao encontrado'], 404);
        }
        if ($currentUser && $currentUser->id === $user->id) {
            return $response->json(['success' => false, 'error' => 'Voce nao pode excluir seu proprio usuario'], 422);
        }
        if ($user->role === 'admin' && (int) $user->active === 1 && $this->activeAdminCount() <= 1) {
            return $response->json(['success' => false, 'error' => 'Nao e possivel excluir o ultimo administrador ativo'], 422);
        }
        if ($this->instanceCount($user->id) > 0) {
            return $response->json([
                'success' => false,
                'error' => 'Este usuario possui instancias. Ele deve excluir as proprias instancias antes da remocao.'
            ], 422);
        }

        $user->delete();
        return $response->json(['success' => true, 'redirect' => '/users']);
    }

    private function validate(array $body, ?int $ignoreId, bool $requirePassword): array
    {
        $errors = [];
        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if ($name === '') {
            $errors[] = 'Informe o nome.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail valido.';
        } elseif (User::emailExists($email, $ignoreId)) {
            $errors[] = 'Este e-mail ja esta em uso.';
        }
        if ($requirePassword && strlen($password) < 6) {
            $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
        }
        if (!$requirePassword && $password !== '' && strlen($password) < 6) {
            $errors[] = 'A nova senha deve ter pelo menos 6 caracteres.';
        }

        return $errors;
    }

    private function activeAdminCount(): int
    {
        $stmt = App::$app->db->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function wouldRemoveLastAdmin(User $user, string $newRole, int $newActive): bool
    {
        return $user->role === 'admin'
            && (int) $user->active === 1
            && ($newRole !== 'admin' || $newActive !== 1)
            && $this->activeAdminCount() <= 1;
    }

    private function instanceCount(int $userId): int
    {
        $stmt = App::$app->db->prepare('SELECT COUNT(*) FROM instances WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
