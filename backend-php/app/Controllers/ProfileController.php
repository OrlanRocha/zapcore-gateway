<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class ProfileController extends Controller
{
    public function edit(Request $request, Response $response)
    {
        $user = Auth::user();
        $view = 'profile/edit';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function update(Request $request, Response $response)
    {
        $user = Auth::user();
        if (!$user) {
            return $response->redirect('/login');
        }

        $body = $request->getBody();
        $errors = [];
        $success = null;

        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $currentPassword = $body['current_password'] ?? '';
        $newPassword = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        if ($name === '') {
            $errors[] = 'Informe seu nome.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail valido.';
        }
        if ($email && User::emailExists($email, $user->id)) {
            $errors[] = 'Este e-mail ja esta em uso.';
        }

        $updateData = [
            'name' => $name,
            'email' => $email
        ];

        if ($newPassword !== '' || $passwordConfirm !== '' || $currentPassword !== '') {
            if (!password_verify($currentPassword, $user->password_hash)) {
                $errors[] = 'A senha atual nao confere.';
            }
            if (strlen($newPassword) < 6) {
                $errors[] = 'A nova senha deve ter pelo menos 6 caracteres.';
            }
            if ($newPassword !== $passwordConfirm) {
                $errors[] = 'A confirmacao da senha nao confere.';
            }
            $updateData['password'] = $newPassword;
        }

        if (!$errors) {
            $user->update($updateData);
            $success = 'Perfil atualizado com sucesso.';
            $freshUser = new User();
            $user = $freshUser->findById($user->id);
        }

        $view = 'profile/edit';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }
}
