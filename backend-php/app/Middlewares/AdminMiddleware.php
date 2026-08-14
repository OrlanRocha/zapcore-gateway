<?php

namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

class AdminMiddleware
{
    public function handle(Request $request, Response $response)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            return $response->redirect('/dashboard');
        }
    }
}
