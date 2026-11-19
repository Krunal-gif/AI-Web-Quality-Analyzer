<?php

namespace Tests\Feature;

use App\Analyzer\Analysis;
use App\Analyzer\Screenshots;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScreenshotsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/webqa-shots-'.uniqid());
        config(['webqa.storage' => $this->tmp]);
        Http::fake(['shop.test/*' => Http::response('<html><head><title>Shop</title></head><body><h1>Shop</h1></body></html>', 200, ['Content-Type' => 'text/html'])]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_pictures_are_off_in_tests_and_for_folders(): void
    {
        $data = $this->postJson('/api/analyses', ['target' => 'https://shop.test/'])->assertCreated()->json();
        $this->assertSame(0, $data['shots']['total']);   // WEBQA_SCREENSHOTS=false in phpunit.xml

        config(['webqa.screenshots' => true]);
        $folder = $this->postJson('/api/analyses', ['target' => base_path('samples/demo-site')])->assertCreated()->json();
        $this->assertSame(0, $folder['shots']['total']);   // only websites have pages to picture
    }

    public function test_missing_chrome_is_explained(): void
    {
        config(['webqa.screenshots' => true, 'webqa.chrome' => '/no/such/chrome']);
        $data = $this->postJson('/api/analyses', ['target' => 'https://shop.test/'])->assertCreated()->json();

        $this->assertFalse($data['shots']['running']);
        $this->assertStringContainsString('Chrome or Chromium is not installed', $data['shots']['error']);
    }

    public function test_picture_route_serves_only_existing_pictures(): void
    {
        $id = $this->postJson('/api/analyses', ['target' => 'https://shop.test/'])->assertCreated()->json('id');
        $key = Screenshots::key('shop.test/index.html');
        File::ensureDirectoryExists("{$this->tmp}/{$id}/shots");
        file_put_contents("{$this->tmp}/{$id}/shots/{$key}", 'RIFF....WEBP');

        $this->get("/analyses/{$id}/shots/{$key}")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get("/analyses/{$id}/shots/0000000000000000.webp")->assertNotFound();
        $this->get("/analyses/{$id}/shots/..%2Fstate.json")->assertNotFound();
        $this->get('/analyses/nope/shots/'.$key)->assertNotFound();
    }

    public function test_progress_is_saved_per_page(): void
    {
        $id = $this->postJson('/api/analyses', ['target' => 'https://shop.test/'])->assertCreated()->json('id');
        Analysis::mutate($id, function (Analysis $a) {
            $a->shots = ['running' => true, 'done' => 1, 'total' => 1, 'updated' => time(), 'error' => null,
                'items' => ['shop.test/index.html' => ['ok' => true, 'error' => null]]];
        });
        $shots = $this->getJson("/api/analyses/{$id}")->json('shots');

        $this->assertTrue($shots['running']);
        $this->assertSame(Screenshots::key('shop.test/index.html'), $shots['items']['shop.test/index.html']['key']);
    }
}
