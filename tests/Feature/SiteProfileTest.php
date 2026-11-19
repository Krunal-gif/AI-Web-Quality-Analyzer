<?php

namespace Tests\Feature;

use App\Analyzer\SiteProfile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteProfileTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteProfile::$resolver = null;
        parent::tearDown();
    }

    public function test_detects_wordpress_on_nginx_with_php(): void
    {
        $p = SiteProfile::build('https://shop.test/', [
            'Server' => ['nginx/1.24.0'],
            'X-Powered-By' => ['PHP/8.2.12'],
            'Set-Cookie' => ['PHPSESSID=abc; path=/'],
        ], '<html><head><meta name="generator" content="WordPress 6.5.2"><link rel="stylesheet" href="/wp-content/themes/x/style.css">'
            .'<script src="/wp-includes/js/jquery/jquery.min.js"></script></head><body class="woocommerce-no-js"></body></html>');

        $this->assertSame(['WordPress', 'WooCommerce'], array_column($p['platform'], 'name'));
        $this->assertSame('6.5.2', $p['platform'][0]['version']);
        $this->assertSame(['PHP', 'MySQL'], $p['languages']['server']);
        $this->assertSame(['HTML', 'CSS', 'JavaScript'], $p['languages']['client']);
        $this->assertSame('Nginx 1.24.0', $p['server']);
        $this->assertNull($p['cdn']);
        $this->assertNull($p['hosting']);   // lookups are off in tests (phpunit.xml)
    }

    public function test_next_js_behind_cloudflare(): void
    {
        $p = SiteProfile::build('https://app.test/', ['server' => 'cloudflare', 'cf-ray' => '8a1-LHR'],
            '<html><body><div id="__next"></div><script id="__NEXT_DATA__" type="application/json">{}</script></body></html>');

        $this->assertSame(['Next.js'], array_column($p['platform'], 'name'));
        $this->assertSame(['Node.js'], $p['languages']['server']);
        $this->assertSame(['React'], array_column($p['frontend'], 'name'));
        $this->assertSame('Cloudflare', $p['cdn']);
        $this->assertNull($p['server']);   // "cloudflare" is the CDN, not the web server
    }

    public function test_hosting_country_and_provider_from_ip(): void
    {
        config(['webqa.geo_lookup' => true]);
        SiteProfile::$resolver = fn (string $host) => $host === 'site.test' ? ['10.0.0.5'] : [];
        $this->assertStringContainsString('Private or local', SiteProfile::hosting('site.test')['error']);   // never sent to the service

        SiteProfile::$resolver = fn () => ['93.184.215.14'];
        Http::fake(['ipwho.is/93.184.215.14' => Http::response(['success' => true, 'country' => 'Germany', 'country_code' => 'DE',
            'region' => 'Hesse', 'city' => 'Frankfurt am Main', 'connection' => ['asn' => 24940, 'org' => 'Hetzner Online GmbH']])]);
        $h = SiteProfile::hosting('site.test', 'Cloudflare');

        $this->assertSame('93.184.215.14', $h['ip']);
        $this->assertSame(['Germany', 'DE', 'Frankfurt am Main'], [$h['country'], $h['country_code'], $h['city']]);
        $this->assertSame(['Hetzner Online GmbH', 'AS24940'], [$h['provider'], $h['asn']]);
        $this->assertTrue($h['behind_cdn']);
        $this->assertNull($h['error']);
    }

    public function test_failed_lookup_is_reported_not_fatal(): void
    {
        config(['webqa.geo_lookup' => true]);
        SiteProfile::$resolver = fn () => ['1.1.1.1'];
        Http::fake(['ipwho.is/*' => Http::response('busy', 503)]);

        $this->assertStringContainsString('Location lookup failed', SiteProfile::hosting('site.test')['error']);
        SiteProfile::$resolver = fn () => [];
        $this->assertStringContainsString('Could not resolve', SiteProfile::hosting('nowhere.test')['error']);
    }

    public function test_profile_is_part_of_a_website_analysis(): void
    {
        config(['webqa.storage' => storage_path('framework/testing/webqa-profile-'.uniqid())]);
        Http::fake(['example.com/*' => Http::response('<html><head><title>Home</title><meta name="generator" content="Drupal 10"></head><body></body></html>',
            200, ['Content-Type' => 'text/html', 'Server' => 'Apache/2.4.58'])]);

        $data = $this->postJson('/api/analyses', ['target' => 'https://example.com/'])->assertCreated()->json();
        $profile = $data['target']['site']['profile'];

        $this->assertSame('Drupal', $profile['platform'][0]['name']);
        $this->assertSame('Apache 2.4.58', $profile['server']);
        $this->assertStringContainsString('Site profile', file_get_contents("{$data['out_dir']}/report.html"));
        \Illuminate\Support\Facades\File::deleteDirectory(dirname($data['out_dir']));
    }
}
