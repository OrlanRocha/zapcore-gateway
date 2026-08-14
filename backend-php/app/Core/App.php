<?php

namespace App\Core;

class App
{
    public static App $app;
    public Router $router;
    public Database $db;

    public function __construct(Router $router)
    {
        self::$app = $this;
        $this->router = $router;
        
        $this->db = new Database([
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? '',
            'user' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? ''
        ]);
    }

    public function run()
    {
        echo $this->router->resolve();
    }
}
