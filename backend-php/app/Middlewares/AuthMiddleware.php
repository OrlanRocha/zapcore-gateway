<?php

namespace App\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;

class AuthMiddleware
{
    public function handle(Request $request, Response $response)
    {
        $user = Auth::user();
        if (!$user || (int) $user->active !== 1) {
            if (Auth::check()) {
                Auth::logout();
            }
            return $response->redirect('/login');
        }

        $path = $request->getUrl();
        if ((int) $user->must_change_password === 1 && !in_array($path, ['/first-login', '/logout'], true)) {
            return $response->redirect('/first-login');
        }
    }
}
