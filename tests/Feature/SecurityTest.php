<?php

namespace Tests\Feature;

use App\Analyzer\Rules\SiteRules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    private function rules(array $headers, array $cookies = [], string $url = 'https://shop.test/'): array
    {
        $issues = SiteRules::security(['url' => $url, 'headers' => $headers, 'cookies' => $cookies]);

        return array_map(fn ($i) => "{$i->rule}:{$i->severity}", $issues);
    }

    public function test_missing_headers_are_reported(): void
    {
        $this->assertSame([
            'sec-hsts-missing:medium', 'sec-csp-missing:medium', 'sec-clickjacking:medium', 'sec-nosniff-missing:low',
            'sec-referrer-policy-missing:low', 'sec-permissions-policy-missing:info', 'sec-version-disclosure:low', 'sec-version-disclosure:low',
        ], $this->rules(['server' => 'Apache/2.4.41 (Ubuntu)', 'x-powered-by' => 'PHP/7.4.3']));
    }

    public function test_well_configured_site_is_clean(): void
    {
        $this->assertSame([], $this->rules([
            'strict-transport-security' => 'max-age=63072000; includeSubDomains; preload',
            'content-security-policy' => "default-src 'self'; frame-ancestors 'none'",
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            'permissions-policy' => 'camera=()',
            'server' => 'nginx',
        ], ['session=abc; Path=/; Secure; HttpOnly; SameSite=Lax', 'XSRF-TOKEN=xyz; Path=/; Secure; SameSite=Lax']));
    }

    public function test_weak_hsts_and_cookie_flags(): void
    {
        $found = $this->rules([
            'strict-transport-security' => 'max-age=86400',
            'content-security-policy-report-only' => "default-src 'self'",
            'x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff', 'referrer-policy' => 'no-referrer', 'permissions-policy' => '',
        ], ['laravel_session=abc; path=/', 'theme=dark; path=/; Secure; HttpOnly; SameSite=Lax', 'tracking=1; path=/; HttpOnly']);

        $this->assertSame(['sec-hsts-weak:low', 'sec-csp-missing:medium', 'sec-cookie-flags:medium', 'sec-cookie-flags:low'], $found);
        $msg = SiteRules::security(['url' => 'https://shop.test/', 'headers' => [], 'cookies' => ['laravel_session=abc; path=/']]);
        $this->assertStringContainsString('“laravel_session” is set without Secure, HttpOnly, SameSite', end($msg)->message);
    }

    public function test_http_site_skips_hsts_and_secure_flag(): void
    {
        $found = $this->rules(['content-security-policy' => "frame-ancestors 'self'", 'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer', 'permissions-policy' => ''], ['id=1; HttpOnly; SameSite=Lax'], 'http://shop.test/');
        $this->assertSame([], $found);
    }

    public function test_security_report_has_only_security_findings(): void
    {
        $tmp = storage_path('framework/testing/webqa-sec-'.uniqid());
        config(['webqa.storage' => $tmp]);
        Http::fake(['example.com/*' => Http::response('<html><head><title>Hi</title></head><body><img src="a.png"><a href="https://x.test" target="_blank">x</a></body></html>',
            200, ['Content-Type' => 'text/html', 'Server' => 'Apache/2.4.41', 'Set-Cookie' => 'PHPSESSID=secretvalue123; path=/'])]);

        $data = $this->postJson('/api/analyses', ['target' => 'https://example.com/'])->assertCreated()->json();
        $categories = array_unique(array_column($data['issues'], 'category'));
        $this->assertContains('Accessibility', $categories);   // the full analysis has other findings too

        $this->get("/analyses/{$data['id']}/security-report")->assertOk();
        $html = file_get_contents("{$data['out_dir']}/security-report.html");
        $this->assertStringContainsString('Security Report', $html);
        $this->assertStringContainsString('Security headers', $html);
        $this->assertStringContainsString('No Strict-Transport-Security header', $html);
        $this->assertStringContainsString('Cookie set without security flags', $html);
        $this->assertStringNotContainsString('Image without alt text', $html);
        $this->assertStringContainsString('Image without alt text', file_get_contents("{$data['out_dir']}/report.html"));
        $this->assertStringNotContainsString('<th>SEO</th>', $html);
        $this->assertStringNotContainsString('secretvalue123', $html);   // cookie values are never printed
        File::deleteDirectory($tmp);
    }
}
