<?php

namespace App\Analyzer\Rules;

use App\Analyzer\Document;
use App\Analyzer\Issue;
use App\Analyzer\SourceLoader;

/**
 * Site-level checks for fetched websites (HTTP status, speed, robots.txt, sitemap, HTTPS, certificate chain, and the
 * security headers and cookies of the first page — read from the response only, nothing is probed).
 */
final class SiteRules
{
    /** @return Issue[] */
    public static function check(array $site): array
    {
        $out = [];
        foreach ($site['pages'] ?? [] as $page) {
            $inSitemap = ($page['source'] ?? '') === 'sitemap' ? ' It is listed in the sitemap.' : '';
            if ($page['status'] === 0) {
                $out[] = Make::issue(null, 'seo-http-error', "{$page['url']} could not be fetched: ".($page['error'] ?? 'no response').'.'.$inSitemap,
                    file: $page['rel'], severity: 'high');
            } elseif ($page['status'] >= 400) {
                $out[] = Make::issue(null, 'seo-http-error', "{$page['url']} returned HTTP {$page['status']}.".$inSitemap, file: $page['rel']);
            }
            if ($page['elapsed_ms'] > 1500) {
                $out[] = Make::issue(null, 'seo-slow-response',
                    "{$page['url']} took {$page['elapsed_ms']} ms to respond (aim for under 800 ms).",
                    file: $page['rel'], severity: $page['elapsed_ms'] > 4000 ? 'high' : null);
            }
        }
        $origin = $site['origin'] ?? '';
        if (str_starts_with($origin, 'http://')) {
            $out[] = Make::issue(null, 'sec-not-https', "{$origin} is served over plain HTTP.", file: '(site)');
        }
        if ($site['tls_chain_incomplete'] ?? false) {
            $out[] = Make::issue(null, 'sec-tls-chain-incomplete',
                "{$origin} sends its certificate without the intermediate certificate. WebQA fetched it itself to continue.", file: '(site)');
        }
        if (($site['robots'] ?? null) === false) {
            $out[] = Make::issue(null, 'seo-robots-missing', "{$origin}/robots.txt returned no robots file.", file: '(site)');
        }
        if (($site['sitemap'] ?? null) === false) {
            $out[] = Make::issue(null, 'seo-sitemap-missing',
                'No sitemap found at robots.txt Sitemap:, /sitemap.xml, /sitemap_index.xml or /wp-sitemap.xml.', file: '(site)');
        }
        if (! empty($site['security'])) {
            array_push($out, ...self::security($site['security']));
        }

        return $out;
    }

    /**
     * Scripts, stylesheets and images on a page that failed to load (statuses fetched by SourceLoader::loadAssets).
     *
     * @param  array<string, array{status:int, type:string}>  $assets
     * @return Issue[]
     */
    public static function brokenResources(Document $doc, array $assets): array
    {
        $out = [];
        $label = ['script' => 'Script', 'stylesheet' => 'Stylesheet', 'image' => 'Image'];
        foreach (SourceLoader::ASSET_TAGS as [$tag, $attr, $type]) {
            foreach ($doc->tree()->findAll($tag) as $node) {
                $raw = trim($node->get($attr));
                if ($raw === '' || ($tag === 'link' && ! preg_match('/\bstylesheet\b/i', $node->get('rel')))) {
                    continue;
                }
                $url = explode('#', SourceLoader::resolve($doc->url ?? '', $raw), 2)[0];
                $status = $assets[$url]['status'] ?? null;
                if ($status === null || ($status > 0 && $status < 400)) {
                    continue;
                }
                $why = $status === 0 ? 'could not be reached' : "returned HTTP {$status}";
                $out[] = Make::issue($doc, 'code-broken-resource', "{$label[$type]} “".Make::short($raw, 80)."” {$why}.",
                    start: $node->start, end: $node->startEnd, severity: $type === 'image' ? 'medium' : null);
            }
        }

        return $out;
    }

    /**
     * @param  array{url:string, headers:array<string,string>, cookies:string[]}  $sec  response headers (lower-case names) and Set-Cookie values
     * @return Issue[]
     */
    public static function security(array $sec): array
    {
        $h = $sec['headers'];
        $url = $sec['url'];
        $https = str_starts_with($url, 'https://');
        $csp = $h['content-security-policy'] ?? '';
        $out = [];
        $add = function (string $rule, string $message, ?string $severity = null) use (&$out) {
            $out[] = Make::issue(null, $rule, $message, file: '(site)', severity: $severity);
        };

        if ($https) {
            if (! isset($h['strict-transport-security'])) {
                $add('sec-hsts-missing', "{$url} does not send Strict-Transport-Security.");
            } elseif (preg_match('/max-age\s*=\s*"?(\d+)/i', $h['strict-transport-security'], $m) && (int) $m[1] < 15_768_000) {
                $add('sec-hsts-weak', "Strict-Transport-Security max-age is {$m[1]} seconds (".round($m[1] / 86400).' days).');
            }
        }
        if ($csp === '') {
            $add('sec-csp-missing', isset($h['content-security-policy-report-only'])
                ? 'Content-Security-Policy is only in report-only mode, so nothing is blocked yet.'
                : "{$url} does not send a Content-Security-Policy.");
        }
        if (! isset($h['x-frame-options']) && ! preg_match('/frame-ancestors/i', $csp)) {
            $add('sec-clickjacking', 'Neither X-Frame-Options nor a CSP frame-ancestors rule is set.');
        }
        if (strtolower(trim($h['x-content-type-options'] ?? '')) !== 'nosniff') {
            $add('sec-nosniff-missing', 'X-Content-Type-Options: nosniff is not set.');
        }
        if (! isset($h['referrer-policy'])) {
            $add('sec-referrer-policy-missing', 'No Referrer-Policy header is sent.');
        }
        if (! isset($h['permissions-policy'])) {
            $add('sec-permissions-policy-missing', 'No Permissions-Policy header is sent.');
        }
        foreach (['server', 'x-powered-by', 'x-aspnet-version', 'x-generator'] as $name) {
            if (isset($h[$name]) && preg_match('/\d+\.\d+/', $h[$name])) {
                $add('sec-version-disclosure', "The {$name} header reveals “{$h[$name]}”.");
            }
        }
        foreach ($sec['cookies'] as $cookie) {
            $name = trim(explode('=', $cookie, 2)[0]);
            $attrs = strtolower(substr($cookie, strpos($cookie, ';') ?: strlen($cookie)));
            $missing = array_values(array_filter([
                $https && ! str_contains($attrs, 'secure') ? 'Secure' : null,
                ! str_contains($attrs, 'httponly') ? 'HttpOnly' : null,
                ! str_contains($attrs, 'samesite') ? 'SameSite' : null,
            ]));
            // XSRF-TOKEN is meant to be read by JavaScript, so only Secure/SameSite matter for it
            if (preg_match('/^(XSRF-TOKEN|csrftoken)$/i', $name)) {
                $missing = array_values(array_diff($missing, ['HttpOnly']));
            }
            if ($missing) {
                $session = preg_match('/sess|token|auth|login|_session$/i', $name);
                $add('sec-cookie-flags', "Cookie “{$name}” is set without ".implode(', ', $missing).'.', $session ? null : 'low');
            }
        }

        return $out;
    }
}
