<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\User;

class AuthController extends Controller
{
    public function showLogin(Request $request, Response $response)
    {
        if (User::countAll() === 0) {
            return $response->redirect('/setup');
        }

        if (Auth::check()) {
            return $response->redirect('/dashboard');
        }
        
        // Pass $view directly to layout
        $view = 'auth/login';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function login(Request $request, Response $response)
    {
        if (User::countAll() === 0) {
            return $response->redirect('/setup');
        }

        $body = $request->getBody();
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        $userModel = new User();
        $user = $userModel->findByEmail($email);

        if ($user && (int) $user->active === 1 && password_verify($password, $user->password_hash)) {
            Auth::login($user);
            if ((int) $user->must_change_password === 1) {
                return $response->redirect('/first-login');
            }
            return $response->redirect('/dashboard');
        }

        $error = "Credenciais invalidas";
        $view = 'auth/login';
        ob_start();
        include __DIR__ . '/../Views/layouts/app.php';
        return ob_get_clean();
    }

    public function logout(Request $request, Response $response)
    {
        Auth::logout();
        return $response->redirect('/login');
    }
}
