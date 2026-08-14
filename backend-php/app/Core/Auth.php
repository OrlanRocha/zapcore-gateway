<?php

namespace App\Core;

use App\Models\User;

class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?User
    {
        if (!self::check()) {
            return null;
        }

        $userModel = new User();
        return $userModel->findById($_SESSION['user_id']);
    }

    public static function login(User $user)
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;
    }

    public static function logout()
    {
        unset($_SESSION['user_id']);
        session_destroy();
    }
}
