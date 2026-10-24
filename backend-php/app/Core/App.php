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
            'host' => Environment::required('DB_HOST'),
            'port' => Environment::required('DB_PORT'),
            'database' => Environment::required('DB_DATABASE'),
            'user' => Environment::required('DB_USERNAME'),
            'password' => Environment::secret('DB_PASSWORD')
        ]);
    }

    public function run()
    {
        echo $this->router->resolve();
    }
}
