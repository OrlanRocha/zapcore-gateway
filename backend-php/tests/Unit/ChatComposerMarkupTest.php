<?php

use PHPUnit\Framework\TestCase;

final class ChatComposerMarkupTest extends TestCase
{
    public function test_chat_composer_exposes_accessible_upload_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root . '/app/Views/instances/show.php');

        foreach (['text', 'image', 'video', 'audio', 'document'] as $mode) {
            $this->assertStringContainsString('data-composer-mode="' . $mode . '"', $view);
        }

        $this->assertStringContainsString('id="chat-media-file"', $view);
        $this->assertStringContainsString('type="file"', $view);
        $this->assertStringContainsString('id="chat-media-dropzone"', $view);
        $this->assertStringContainsString('Arraste', $view);
        $this->assertStringContainsString('256 MB', $view);
        $this->assertStringContainsString('id="chat-selected-file"', $view);
        $this->assertStringContainsString('id="chat-media-url"', $view);
        $this->assertStringContainsString('id="chat-composer-status"', $view);
        $this->assertStringContainsString('aria-live="polite"', $view);
        $this->assertStringContainsString('/js/chat-media-composer.js', $view);
    }

    public function test_composer_script_uses_form_data_and_drag_events(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/js/chat-media-composer.js');

        $this->assertStringContainsString('new FormData', $script);
        $this->assertStringContainsString("addEventListener('drop'", $script);
        $this->assertStringContainsString("'dragover'", $script);
        $this->assertStringContainsString('268435456', $script);
        $this->assertStringContainsString('window.ChatMediaComposer', $script);
    }
}
