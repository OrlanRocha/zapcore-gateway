<?php

namespace App\Core;

abstract class Controller
{
    public function render(string $view, array $params = [])
    {
        return App::$app->router->renderView($view, $params);
    }
}
