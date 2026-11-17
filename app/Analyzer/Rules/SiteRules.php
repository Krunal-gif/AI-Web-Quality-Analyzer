<?php

namespace App\Analyzer\Rules;

use App\Analyzer\Issue;

/**
 * Site-level checks for fetched websites (HTTP status, speed, robots.txt, sitemap, HTTPS, certificate chain).
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

        return $out;
    }
}
