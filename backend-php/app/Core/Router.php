<?php

namespace App\Core;

class Router
{
    public array $routes = [];
    public Request $request;
    public Response $response;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    public function get($path, $callback, $middleware = [])
    {
        $this->addRoute('get', $path, $callback, $middleware);
    }

    public function post($path, $callback, $middleware = [])
    {
        $this->addRoute('post', $path, $callback, $middleware);
    }

    private function addRoute($method, $path, $callback, $middleware = [])
    {
        $path = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<\1>[a-zA-Z0-9_-]+)', $path);
        $this->routes[$method]['#^' . $path . '$#'] = [
            'callback' => $callback,
            'middleware' => is_array($middleware) ? $middleware : [$middleware]
        ];
    }

    public function resolve()
    {
        $method = $this->request->getMethod();
        $url = $this->request->getUrl();

        $routes = $this->routes[$method] ?? [];
        
        foreach ($routes as $routePattern => $routeConfig) {
            if (preg_match($routePattern, $url, $matches)) {
                $callback = $routeConfig['callback'];
                $middlewares = $routeConfig['middleware'];
                
                // Execute middlewares
                foreach ($middlewares as $middleware) {
                    $mw = new $middleware();
                    $mw->handle($this->request, $this->response);
                }

                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                if (is_string($callback)) {
                    // Assuming views
                    return $this->renderView($callback);
                }

                if (is_array($callback)) {
                    $callback[0] = new $callback[0]();
                }

                return call_user_func($callback, $this->request, $this->response, ...array_values($params));
            }
        }

        $this->response->setStatusCode(404);
        echo "404 Not Found";
        exit;
    }

    public function renderView($view, $params = [])
    {
        foreach ($params as $key => $value) {
            $$key = $value;
        }
        ob_start();
        include_once __DIR__ . "/../Views/$view.php";
        return ob_get_clean();
    }
}
