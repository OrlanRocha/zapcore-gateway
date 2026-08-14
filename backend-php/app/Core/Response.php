<?php

namespace App\Core;

class Response
{
    public function setStatusCode(int $code)
    {
        http_response_code($code);
    }

    public function json(array $data, int $statusCode = 200)
    {
        $this->setStatusCode($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function success(array $data = [], string $message = 'Operacao realizada com sucesso', int $statusCode = 200)
    {
        return $this->json([
            'success' => true,
            'data' => $data,
            'message' => $message
        ], $statusCode);
    }

    public function error(string $error, int $statusCode = 400)
    {
        return $this->json([
            'success' => false,
            'error' => $error
        ], $statusCode);
    }

    public function redirect(string $url)
    {
        header("Location: $url");
        exit;
    }
}
