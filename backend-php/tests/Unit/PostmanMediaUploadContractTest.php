<?php

use PHPUnit\Framework\TestCase;

final class PostmanMediaUploadContractTest extends TestCase
{
    public function test_collection_keeps_url_request_and_adds_multipart_uploads(): void
    {
        $root = dirname(__DIR__, 3);
        $collection = json_decode(file_get_contents($root . '/docs/ZapCore-Gateway.postman_collection.json'), true, 512, JSON_THROW_ON_ERROR);
        $requests = $this->flatten($collection['item'] ?? []);

        $urlRequest = $this->find($requests, 'Enviar midia por URL - usuario');
        $this->assertSame('raw', $urlRequest['request']['body']['mode'] ?? null);
        $this->assertStringContainsString('media_url', $urlRequest['request']['body']['raw'] ?? '');

        foreach (['Upload de imagem - usuario', 'Upload de documento - usuario'] as $name) {
            $request = $this->find($requests, $name);
            $this->assertSame('formdata', $request['request']['body']['mode'] ?? null);
            $fields = array_column($request['request']['body']['formdata'] ?? [], null, 'key');
            $this->assertSame('file', $fields['media']['type'] ?? null);
            foreach (['instance_uuid', 'chat_type', 'to', 'media_type', 'media'] as $field) {
                $this->assertArrayHasKey($field, $fields);
            }
        }
    }

    public function test_documentation_declares_limits_errors_and_retention_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $documentation = file_get_contents($root . '/docs/api.md')
            . file_get_contents($root . '/docs/installation.md')
            . file_get_contents($root . '/README.md');

        $this->assertStringContainsString('256 MiB', $documentation);
        $this->assertStringContainsString('client_max_body_size 260M', $documentation);
        $this->assertStringContainsString('413', $documentation);
        $this->assertStringContainsString('422', $documentation);
        $this->assertStringContainsString('410', $documentation);
        $this->assertStringContainsString('storage-retention.php', $documentation);
    }

    private function flatten(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (isset($item['request'])) $result[] = $item;
            if (isset($item['item'])) $result = array_merge($result, $this->flatten($item['item']));
        }
        return $result;
    }

    private function find(array $requests, string $name): array
    {
        foreach ($requests as $request) if (($request['name'] ?? '') === $name) return $request;
        $this->fail("Postman request not found: {$name}");
    }
}
