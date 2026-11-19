<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaErrorsTest extends TestCase
{
    public function test_broken_ollama_install_stops_the_batch_with_a_clear_message(): void
    {
        $tmp = storage_path('framework/testing/webqa-ollama-'.uniqid());
        config(['webqa.storage' => $tmp]);
        Http::fake([
            '*/api/chat' => Http::response(['error' => 'error starting llama-server: llama-server binary not found (checked: /usr/local/lib/ollama/llama-server)'], 500),
            'shop.test/*' => Http::response('<html><body><img src="a.png"><img src="b.png"><p>Hi</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $data = $this->postJson('/api/analyses', ['target' => 'https://shop.test/'])->assertCreated()->json();
        $this->assertGreaterThan(3, count($data['issues']));

        $this->postJson("/api/analyses/{$data['id']}/ai", [])->assertStatus(202);
        $state = $this->getJson("/api/analyses/{$data['id']}")->json();

        $this->assertSame(1, $state['batch']['done']);   // stopped after the first failure, not one per issue
        $this->assertSame(1, $state['batch']['errors']);
        $this->assertStringStartsWith('Ollama can\'t run models: its installation is incomplete', $state['batch']['last_error']);
        $this->assertStringContainsString('curl -fsSL https://ollama.com/install.sh | sh', $state['batch']['last_error']);
        $this->assertFalse($state['batch']['running']);
        $this->assertSame([], collect($state['issues'])->where('ai_pending', true)->all());
        File::deleteDirectory($tmp);
    }
}
