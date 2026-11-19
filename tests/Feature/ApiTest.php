<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/webqa-api-'.uniqid());
        config(['webqa.storage' => "{$this->tmp}/analyses"]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function analyse(): array
    {
        return $this->postJson('/api/analyses', ['target' => base_path('samples/demo-site')])->assertCreated()->json();
    }

    public function test_spa_shell_is_served(): void
    {
        $this->get('/')->assertOk()->assertSee('csrf-token', false);
    }

    public function test_analyse_review_apply_flow(): void
    {
        $data = $this->analyse();
        $this->assertCount(49, $data['issues']);
        $id = $data['id'];

        $viewport = collect($data['issues'])->firstWhere('rule', 'seo-viewport-missing');
        $this->patchJson("/api/analyses/{$id}/issues/{$viewport['id']}", ['status' => 'approved'])->assertOk();

        $manual = collect($data['issues'])->firstWhere('editable', false);
        $this->patchJson("/api/analyses/{$id}/issues/{$manual['id']}", ['status' => 'approved'])
            ->assertStatus(422)->assertJsonPath('message', 'There is no proposed fix to approve yet — ask AI or edit one first.');

        $rest = collect($data['issues'])->where('proposed_source', 'rule')->pluck('id')->all();
        $this->postJson("/api/analyses/{$id}/bulk", ['ids' => $rest, 'status' => 'approved'])->assertOk()->assertJsonPath('changed', 32);

        $after = $this->postJson("/api/analyses/{$id}/apply", ['mode' => 'patch'])->assertOk()->json('after');
        $this->assertSame(32, $after['applied']);
        $this->assertSame(32, $after['resolved']);

        $report = $this->get("/analyses/{$id}/report")->assertOk();
        $this->assertStringContainsString('Final Fix Report', file_get_contents($report->baseResponse->getFile()->getPathname()));
        $this->get("/analyses/{$id}/changes.patch")->assertOk();
        $this->getJson('/api/analyses')->assertOk()->assertJsonPath('history.0.id', $id);
    }

    public function test_write_one_fix_endpoint(): void
    {
        File::copyDirectory(base_path('samples/demo-site'), "{$this->tmp}/site");
        $data = $this->postJson('/api/analyses', ['target' => "{$this->tmp}/site"])->assertCreated()->json();
        $viewport = collect($data['issues'])->firstWhere('rule', 'seo-viewport-missing');

        $state = $this->postJson("/api/analyses/{$data['id']}/issues/{$viewport['id']}/write")->assertOk()->json();
        $written = collect($state['issues'])->firstWhere('id', $viewport['id']);
        $this->assertTrue($written['written']);
        $this->assertTrue($written['resolved']);
        $this->assertStringContainsString('name="viewport"', file_get_contents("{$this->tmp}/site/index.html"));

        $this->postJson("/api/analyses/{$data['id']}/issues/{$viewport['id']}/write")
            ->assertStatus(422)->assertJsonPath('message', 'This fix is already written to the file.');
    }

    public function test_analysis_can_be_removed(): void
    {
        $id = $this->analyse()['id'];
        $this->getJson('/api/analyses')->assertJsonPath('history.0.id', $id)->assertJsonPath('history.0.backups', false);

        $this->deleteJson("/api/analyses/{$id}")->assertOk()->assertJsonPath('deleted', $id)->assertJsonCount(0, 'history');
        $this->assertDirectoryDoesNotExist("{$this->tmp}/analyses/{$id}");
        $this->getJson("/api/analyses/{$id}")->assertNotFound();
        $this->deleteJson("/api/analyses/{$id}")->assertNotFound();
        $this->assertDirectoryExists(base_path('samples/demo-site'));
    }

    public function test_ai_review_stores_validated_proposal(): void
    {
        Http::fake([
            '*/api/chat' => Http::response(['message' => ['content' => json_encode([
                'why' => 'Screen readers announce the file name instead.',
                'fix' => 'Describe the logo.',
                'corrected' => '<img src="images/acme-logo.png" alt="Acme Plumbing logo">',
                'confidence' => 'high',
            ])]]),
        ]);
        $data = $this->analyse();
        $img = collect($data['issues'])->first(fn ($i) => str_contains($i['message'], 'acme-logo'));

        $this->postJson("/api/analyses/{$data['id']}/ai", ['ids' => [$img['id']]])->assertStatus(202);
        // the work runs in a terminating callback after the response; tests run it on terminate
        $state = $this->getJson("/api/analyses/{$data['id']}")->json();
        $issue = collect($state['issues'])->firstWhere('id', $img['id']);

        $this->assertSame('ai', $issue['proposed_source']);
        $this->assertSame('<img src="images/acme-logo.png" alt="Acme Plumbing logo">', $issue['proposed']);
        $this->assertSame('Screen readers announce the file name instead.', $issue['ai']['why']);
        $this->assertFalse($issue['ai_pending']);
        $this->assertFalse($state['batch']['running']);
    }

    public function test_ai_offline_is_reported_not_fatal(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);
        $this->getJson('/api/ai/status')->assertOk()->assertJsonPath('online', false);

        $data = $this->analyse();
        $id = $data['issues'][0]['id'];
        $this->postJson("/api/analyses/{$data['id']}/ai", ['ids' => [$id]])->assertStatus(202);
        $issue = collect($this->getJson("/api/analyses/{$data['id']}")->json('issues'))->firstWhere('id', $id);
        $this->assertStringContainsString('Cannot reach Ollama', $issue['ai']['error']);
    }

    public function test_remove_all_clears_history(): void
    {
        $this->analyse();
        $this->analyse();
        $this->assertCount(2, $this->getJson('/api/analyses')->json('history'));

        $this->deleteJson('/api/analyses')->assertOk()->assertJsonPath('removed', 2)->assertJsonPath('history', []);
        $this->get('/history')->assertOk();
        $this->assertDirectoryExists(base_path('samples/demo-site'));
    }

    public function test_unknown_analysis_is_404(): void
    {
        $this->getJson('/api/analyses/does-not-exist')->assertNotFound();
        $this->getJson('/api/analyses/..%2F..%2Fetc')->assertNotFound();
    }

    public function test_bad_target_is_a_friendly_error(): void
    {
        $this->postJson('/api/analyses', ['target' => '/no/such/path'])
            ->assertStatus(422)->assertJsonPath('message', 'Not a URL, folder or file: /no/such/path');
    }
}
