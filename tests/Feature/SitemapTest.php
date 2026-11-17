<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/webqa-sitemap-'.uniqid());
        config(['webqa.storage' => "{$this->tmp}/analyses"]);

        $page = fn (string $title, string $body = '') => Http::response(
            "<!DOCTYPE html><html><head><title>{$title}</title></head><body><h1>{$title}</h1>{$body}<img src=\"a.png\"></body></html>",
            200, ['Content-Type' => 'text/html; charset=utf-8']);
        Http::fake([
            'example.com/robots.txt' => Http::response("User-agent: *\nSitemap: https://example.com/sitemap_index.xml\n"),
            'example.com/sitemap_index.xml' => Http::response('<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sitemap><loc>https://example.com/page-sitemap.xml</loc></sitemap></sitemapindex>'),
            'example.com/page-sitemap.xml' => Http::response('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/</loc></url><url><loc>https://www.example.com/about/</loc></url>'
                .'<url><loc><![CDATA[https://example.com/contact/?a=1&amp;b=2]]></loc></url>'
                .'<url><loc>https://example.com/gone/</loc></url><url><loc>https://other.com/x</loc></url></urlset>'),
            'example.com/gone/' => Http::response('Not found', 404),
            'www.example.com/about/' => $page('About'),
            'example.com/contact/*' => $page('Contact'),
            'example.com/hidden/' => $page('Hidden'),
            'example.com' => $page('Home', '<a href="/hidden/">hidden</a>'),
            'example.com/' => $page('Home', '<a href="/hidden/">hidden</a>'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_whole_site_reads_sitemap_first_then_links_and_reports_issues_per_page(): void
    {
        $data = $this->postJson('/api/analyses', ['target' => 'https://example.com/', 'pages' => 10])->assertCreated()->json();
        $site = $data['target']['site'];

        $this->assertSame('https://example.com/sitemap_index.xml', $site['sitemap_url']);
        $this->assertSame(4, $site['sitemap_total']);   // other.com is not this site
        $this->assertSame(
            ['https://example.com/', 'https://www.example.com/about/', 'https://example.com/contact/?a=1&b=2', 'https://example.com/gone/', 'https://example.com/hidden/'],
            array_column($site['pages'], 'url'));
        $this->assertSame(['start', 'sitemap', 'sitemap', 'sitemap', 'link'], array_column($site['pages'], 'source'));
        $this->assertCount(4, $data['target']['files']);   // the 404 page itself isn't analysed

        $gone = collect($site['pages'])->firstWhere('status', 404);
        $this->assertFalse($gone['analysed']);
        $this->assertSame(1, $gone['issues']);
        $about = collect($site['pages'])->firstWhere('url', 'https://www.example.com/about/');
        $this->assertTrue($about['analysed']);
        $this->assertSame(collect($data['issues'])->where('file', $about['rel'])->count(), $about['issues']);
        $this->assertGreaterThan(0, $about['issues']);

        $this->get("/analyses/{$data['id']}/report")->assertOk();
        $html = file_get_contents("{$data['out_dir']}/report.html");
        $this->assertStringContainsString('<code>https://example.com/sitemap_index.xml</code>', $html);
        $this->assertStringContainsString('https://example.com/hidden/', $html);
    }

    public function test_sitemap_url_as_target_analyses_exactly_its_pages(): void
    {
        $data = $this->postJson('/api/analyses', ['target' => 'https://example.com/page-sitemap.xml'])->assertCreated()->json();

        $this->assertSame(['sitemap', 'sitemap', 'sitemap', 'sitemap'], array_column($data['target']['site']['pages'], 'source'));
        $this->assertCount(3, $data['target']['files']);
    }

    public function test_page_limit_is_respected(): void
    {
        $data = $this->postJson('/api/analyses', ['target' => 'https://example.com/', 'pages' => 2])->assertCreated()->json();

        $this->assertCount(2, $data['target']['files']);
        $this->assertSame(4, $data['target']['site']['sitemap_total']);
    }

    public function test_single_page_does_not_crawl(): void
    {
        $data = $this->postJson('/api/analyses', ['target' => 'https://example.com/'])->assertCreated()->json();

        $this->assertCount(1, $data['target']['files']);
        $this->assertArrayNotHasKey('sitemap_url', $data['target']['site']);
    }

    public function test_not_a_sitemap_is_a_friendly_error(): void
    {
        Http::fake(['example.com/nope.xml' => Http::response('<html>nope</html>')]);
        $this->postJson('/api/analyses', ['target' => 'https://example.com/nope.xml'])->assertStatus(422);
    }
}
