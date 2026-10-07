<?php

namespace App\Core;

class Request
{
    private array $attributes = [];

    public function getMethod(): string
    {
        return strtolower($_SERVER['REQUEST_METHOD']);
    }

    public function getUrl(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }
        return $path;
    }

    public function getBody(): array
    {
        $body = [];

        if ($this->getMethod() === 'get') {
            foreach ($_GET as $key => $value) {
                $body[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
            }
        }

        if ($this->getMethod() === 'post') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $body = $data;
                }
            } else {
                foreach ($_POST as $key => $value) {
                    $body[$key] = is_array($value)
                        ? self::sanitizeArray($value)
                        : filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
                }
            }
        }

        return $body;
    }

    public function getUploadedFile(string $field): ?array
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return null;
        }

        return [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    private static function sanitizeArray(array $values): array
    {
        return array_map(static function ($value) {
            if (is_array($value)) {
                return self::sanitizeArray($value);
            }
            return filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
        }, $values);
    }
    
    public function getHeader(string $name): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $nameLower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return $value;
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }

        return null;
    }
    
    public function getBearerToken(): ?string
    {
        $header = $this->getHeader('Authorization');
        if ($header && preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
