<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\TokenService;

class SetupController extends Controller
{
    public function setup(Request $request, Response $response)
    {
        if (User::countAll() > 0) {
            return $response->redirect(Auth::check() ? '/dashboard' : '/login');
        }

        $view = 'setup/index';
        $errors = [];
        $old = [];
        $forcePublicLayout = true;
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function store(Request $request, Response $response)
    {
        if (User::countAll() > 0) {
            return $response->redirect(Auth::check() ? '/dashboard' : '/login');
        }

        $body = $request->getBody();
        $errors = $this->validateSetup($body);

        if ($errors) {
            $view = 'setup/index';
            $old = $body;
            $forcePublicLayout = true;
            ob_start();
            include __DIR__ . '/../Views/layouts/app.php';
            return ob_get_clean();
        }

        $userId = User::create([
            'name' => trim($body['name']),
            'email' => strtolower(trim($body['email'])),
            'password' => $body['password'],
            'role' => 'admin',
            'active' => 1,
            'must_change_password' => 0
        ]);

        $userModel = new User();
        $user = $userModel->findById($userId);
        Auth::login($user);

        $issuedToken = !empty($body['create_token'])
            ? TokenService::issue($userId, 'Initial Admin Token')
            : null;

        $view = 'setup/complete';
        $forcePublicLayout = true;
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function firstLogin(Request $request, Response $response)
    {
        $user = Auth::user();
        if (!$user) {
            return $response->redirect('/login');
        }

        if ((int) $user->must_change_password !== 1) {
            return $response->redirect('/dashboard');
        }

        $view = 'setup/first-login';
        $errors = [];
        $old = [
            'name' => $user->name,
            'email' => $user->email
        ];
        $forcePublicLayout = true;
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function completeFirstLogin(Request $request, Response $response)
    {
        $user = Auth::user();
        if (!$user) {
            return $response->redirect('/login');
        }

        if ((int) $user->must_change_password !== 1) {
            return $response->redirect('/dashboard');
        }

        $body = $request->getBody();
        $errors = $this->validateFirstLogin($body, $user->id);

        if ($errors) {
            $view = 'setup/first-login';
            $old = $body;
            $forcePublicLayout = true;
            ob_start();
            include __DIR__ . '/../Views/layouts/app.php';
            return ob_get_clean();
        }

        $user->update([
            'name' => trim($body['name']),
            'email' => strtolower(trim($body['email'])),
            'password' => $body['password'],
            'must_change_password' => 0,
            'active' => 1
        ]);

        return $response->redirect('/dashboard');
    }

    private function validateSetup(array $body): array
    {
        $errors = [];
        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['password_confirm'] ?? '');

        if ($name === '') {
            $errors[] = 'Informe o nome do administrador.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail valido.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'A senha deve ter pelo menos 8 caracteres.';
        }
        if ($password !== $confirm) {
            $errors[] = 'A confirmacao da senha nao confere.';
        }

        return $errors;
    }

    private function validateFirstLogin(array $body, int $userId): array
    {
        $errors = [];
        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['password_confirm'] ?? '');

        if ($name === '') {
            $errors[] = 'Informe seu nome.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail valido.';
        } elseif (User::emailExists($email, $userId)) {
            $errors[] = 'Este e-mail ja esta em uso.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'A nova senha deve ter pelo menos 8 caracteres.';
        }
        if ($password === 'admin123') {
            $errors[] = 'Escolha uma senha diferente da senha temporaria.';
        }
        if ($password !== $confirm) {
            $errors[] = 'A confirmacao da senha nao confere.';
        }

        return $errors;
    }
}
