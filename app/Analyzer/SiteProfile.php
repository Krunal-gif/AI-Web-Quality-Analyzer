<?php

namespace App\Analyzer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * What a website is built with and where it is hosted, worked out from one fetched page:
 * platform (CMS, shop, site builder or framework), server and client languages, JavaScript and CSS libraries,
 * web server and CDN from the HTML, response headers and cookies; hosting country and provider from the
 * server's IP address (looked up with a geolocation service, config webqa.geo_url).
 *
 * @phpstan-type Profile array{platform:array, languages:array{server:string[], client:string[]}, frontend:array,
 *     server:?string, cdn:?string, hosting:?array}
 */
final class SiteProfile
{
    /**
     * Signatures. html/header/cookie/generator are case-insensitive regexes; header is [name => value regex].
     * implies: languages or technologies that come with it. version: regex whose first group is the version.
     */
    private const TECH = [
        // ---- platforms: CMS, e-commerce, site builders
        'WordPress' => ['type' => 'platform', 'generator' => 'WordPress', 'html' => '/wp-(content|includes)\//', 'header' => ['link' => 'api\.w\.org'], 'implies' => ['PHP', 'MySQL'], 'version' => 'WordPress ([\d.]+)'],
        'WooCommerce' => ['type' => 'platform', 'html' => '/plugins\/woocommerce\/|wc-ajax=|class="[^"]*woocommerce-no-js/', 'implies' => ['WordPress', 'PHP']],
        'Shopify' => ['type' => 'platform', 'html' => '/cdn\.shopify\.com|Shopify\.theme/', 'header' => ['x-shopid' => '', 'x-shopify-stage' => ''], 'implies' => ['Liquid']],
        'Wix' => ['type' => 'platform', 'generator' => 'Wix\.com', 'html' => '/static\.wixstatic\.com|wix-bolt/', 'header' => ['x-wix-request-id' => '']],
        'Squarespace' => ['type' => 'platform', 'html' => '/static1\.squarespace\.com|Static\.SQUARESPACE_CONTEXT/'],
        'Webflow' => ['type' => 'platform', 'generator' => 'Webflow', 'html' => '/data-wf-(page|site)=/'],
        'Drupal' => ['type' => 'platform', 'generator' => 'Drupal', 'html' => '/drupal-settings-json|\/sites\/default\/files\//', 'header' => ['x-drupal-cache' => '', 'x-generator' => 'Drupal'], 'implies' => ['PHP'], 'version' => 'Drupal ([\d.]+)'],
        'Joomla' => ['type' => 'platform', 'generator' => 'Joomla', 'html' => '/\/media\/jui\/|com_content/', 'implies' => ['PHP'], 'version' => 'Joomla! ([\d.]+)'],
        'Magento' => ['type' => 'platform', 'html' => '/Mage\.Cookies|\/static\/version\d+\/frontend\/|mage\/cookies/', 'cookie' => '/^(frontend|mage-cache-storage)$/', 'implies' => ['PHP']],
        'PrestaShop' => ['type' => 'platform', 'generator' => 'PrestaShop', 'html' => '/prestashop/', 'cookie' => '/^PrestaShop-/', 'implies' => ['PHP']],
        'BigCommerce' => ['type' => 'platform', 'html' => '/cdn\d*\.bigcommerce\.com/'],
        'Ghost' => ['type' => 'platform', 'generator' => 'Ghost', 'implies' => ['Node.js'], 'version' => 'Ghost ([\d.]+)'],
        // hs-scripts.com is HubSpot's tracking script, used on many non-HubSpot sites, so it is not evidence
        'HubSpot CMS' => ['type' => 'platform', 'generator' => 'HubSpot', 'html' => '/\.hs-sites\.com|hubspot-cms/', 'header' => ['x-hs-hub-id' => '']],
        'Statamic' => ['type' => 'platform', 'header' => ['x-powered-by' => 'Statamic'], 'implies' => ['Laravel', 'PHP']],
        'Framer' => ['type' => 'platform', 'generator' => 'Framer', 'html' => '/framerusercontent\.com/'],
        'Bedrock' => ['type' => 'platform', 'html' => '/\/app\/(themes|plugins|uploads)\//', 'implies' => ['WordPress', 'PHP']],
        // ---- back-end frameworks
        'Laravel' => ['type' => 'platform', 'cookie' => '/^laravel_session$/', 'header' => ['x-powered-by' => 'Laravel'], 'implies' => ['PHP'], 'version' => 'Laravel ([\d.]+)'],
        'Symfony' => ['type' => 'platform', 'header' => ['x-debug-token' => ''], 'implies' => ['PHP']],
        'Django' => ['type' => 'platform', 'html' => '/csrfmiddlewaretoken/', 'cookie' => '/^(csrftoken|django_language)$/', 'implies' => ['Python']],
        'Ruby on Rails' => ['type' => 'platform', 'html' => '/<meta name="csrf-param" content="authenticity_token"/', 'header' => ['x-runtime' => '^[\d.]+$'], 'implies' => ['Ruby']],
        'ASP.NET' => ['type' => 'platform', 'html' => '/__VIEWSTATE|__EVENTVALIDATION/', 'header' => ['x-aspnet-version' => '', 'x-powered-by' => 'ASP\.NET'], 'cookie' => '/^(ASP\.NET_SessionId|\.AspNetCore\.)/', 'implies' => ['C# (.NET)'], 'version' => null],
        'Express' => ['type' => 'platform', 'header' => ['x-powered-by' => '^Express'], 'implies' => ['Node.js']],
        'Next.js' => ['type' => 'platform', 'html' => '/__NEXT_DATA__|\/_next\/static\//', 'header' => ['x-powered-by' => 'Next\.js'], 'implies' => ['React', 'Node.js']],
        'Nuxt' => ['type' => 'platform', 'html' => '/__NUXT__|\/_nuxt\//', 'implies' => ['Vue.js', 'Node.js']],
        'Gatsby' => ['type' => 'platform', 'generator' => 'Gatsby', 'html' => '/id="___gatsby"/', 'implies' => ['React']],
        'Astro' => ['type' => 'platform', 'generator' => 'Astro', 'html' => '/<astro-island/'],
        // ---- server languages (direct evidence)
        'PHP' => ['type' => 'language', 'header' => ['x-powered-by' => 'PHP'], 'cookie' => '/^PHPSESSID$/', 'html' => '/href="[^"]+\.php(\?|")/', 'version' => 'PHP\/([\d.]+)'],
        'Java' => ['type' => 'language', 'cookie' => '/^JSESSIONID$/', 'header' => ['x-powered-by' => 'Servlet|JSP']],
        'Python' => ['type' => 'language', 'header' => ['server' => 'gunicorn|uvicorn|Werkzeug|WSGIServer']],
        'Node.js' => ['type' => 'language'],
        'Ruby' => ['type' => 'language', 'header' => ['server' => 'Phusion Passenger|Puma']],
        'C# (.NET)' => ['type' => 'language'],
        'Liquid' => ['type' => 'language'],
        'MySQL' => ['type' => 'database'],
        // ---- JavaScript / CSS
        'React' => ['type' => 'frontend', 'html' => '/data-reactroot|react(-dom)?(\.production)?(\.min)?\.js|__REACT_DEVTOOLS/'],
        'Vue.js' => ['type' => 'frontend', 'html' => '/vue(\.runtime)?(\.global)?(\.prod)?(\.min)?\.js|data-v-[0-9a-f]{8}|data-server-rendered/'],
        'Angular' => ['type' => 'frontend', 'html' => '/ng-version=|ng-app=|angular(\.min)?\.js/'],
        'Svelte' => ['type' => 'frontend', 'html' => '/class="[^"]*svelte-[a-z0-9]+/'],
        'Alpine.js' => ['type' => 'frontend', 'html' => '/x-data=|alpine(\.min)?\.js|alpinejs/'],
        'jQuery' => ['type' => 'frontend', 'html' => '/jquery([.-][\d.]+)?(\.min)?\.js/', 'version' => 'jquery[.-]([\d.]+)(?:\.min)?\.js'],
        'Bootstrap' => ['type' => 'frontend', 'html' => '/bootstrap(\.bundle)?(\.min)?\.(css|js)/'],
        'Tailwind CSS' => ['type' => 'frontend', 'html' => '/tailwindcss|tailwind(\.min)?\.css/'],
        'Elementor' => ['type' => 'frontend', 'html' => '/elementor/', 'implies' => ['WordPress']],
        // ---- web servers
        'Nginx' => ['type' => 'server', 'header' => ['server' => '^nginx'], 'version' => 'nginx\/([\d.]+)'],
        'Apache' => ['type' => 'server', 'header' => ['server' => '^Apache'], 'version' => 'Apache\/([\d.]+)'],
        'LiteSpeed' => ['type' => 'server', 'header' => ['server' => 'LiteSpeed', 'x-litespeed-cache' => '']],
        'Microsoft IIS' => ['type' => 'server', 'header' => ['server' => 'Microsoft-IIS'], 'version' => 'Microsoft-IIS\/([\d.]+)', 'implies' => ['C# (.NET)']],
        'Caddy' => ['type' => 'server', 'header' => ['server' => '^Caddy']],
        'OpenResty' => ['type' => 'server', 'header' => ['server' => 'openresty']],
        // ---- CDNs and hosting platforms (the IP then belongs to them, not the origin server)
        'Cloudflare' => ['type' => 'cdn', 'header' => ['cf-ray' => '', 'server' => '^cloudflare']],
        'Amazon CloudFront' => ['type' => 'cdn', 'header' => ['x-amz-cf-id' => '', 'via' => 'CloudFront']],
        'Fastly' => ['type' => 'cdn', 'header' => ['x-fastly-request-id' => '', 'x-served-by' => 'cache-']],
        'Akamai' => ['type' => 'cdn', 'header' => ['x-akamai-transformed' => '', 'server' => 'AkamaiGHost']],
        'Vercel' => ['type' => 'cdn', 'header' => ['x-vercel-id' => '', 'server' => '^Vercel']],
        'Netlify' => ['type' => 'cdn', 'header' => ['x-nf-request-id' => '', 'server' => '^Netlify']],
        'Sucuri' => ['type' => 'cdn', 'header' => ['x-sucuri-id' => '']],
        'WP Engine' => ['type' => 'cdn', 'header' => ['x-powered-by' => 'WP Engine', 'wpe-backend' => '']],
        'Kinsta' => ['type' => 'cdn', 'header' => ['x-kinsta-cache' => '']],
        'GitHub Pages' => ['type' => 'cdn', 'header' => ['x-github-request-id' => '']],
    ];

    /** For tests: replaces DNS lookups (host => list of IPs). */
    public static ?\Closure $resolver = null;

    /**
     * @param  array<string, string[]|string>  $headers  response headers (any case)
     * @return array<string, mixed>
     */
    public static function build(string $url, array $headers, string $html): array
    {
        $headers = array_change_key_case(array_map(fn ($v) => implode(', ', (array) $v), $headers));
        $cookies = [];
        foreach (preg_split('/,\s*(?=[^;,=\s]+=)/', $headers['set-cookie'] ?? '') as $c) {
            if (preg_match('/^\s*([^=;\s]+)=/', $c, $m)) {
                $cookies[] = $m[1];
            }
        }
        $generator = preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']generator["\']/i', $html, $m) ? html_entity_decode($m[1]) : '';
        $head = substr($html, 0, 600_000);

        $found = [];   // name => version|null
        foreach (self::TECH as $name => $t) {
            if (self::matches($t, $headers, $cookies, $generator, $head)) {
                $found[$name] = self::version($t, $headers, $generator, $head);
            }
        }
        // add what the findings imply (WordPress → PHP, Next.js → React + Node.js), until nothing new appears
        do {
            $added = false;
            foreach (array_keys($found) as $name) {
                foreach (self::TECH[$name]['implies'] ?? [] as $implied) {
                    if (! array_key_exists($implied, $found)) {
                        $found[$implied] = null;
                        $added = true;
                    }
                }
            }
        } while ($added);

        $pick = fn (string $type) => array_values(array_filter(array_map(
            fn ($name, $version) => self::TECH[$name]['type'] === $type ? ['name' => $name, 'version' => $version] : null,
            array_keys($found), $found)));
        $client = ['HTML'];
        if (preg_match('/<(link[^>]+stylesheet|style)\b/i', $head)) {
            $client[] = 'CSS';
        }
        if (stripos($head, '<script') !== false) {
            $client[] = 'JavaScript';
        }
        $cdn = $pick('cdn')[0]['name'] ?? null;
        $server = $pick('server')[0] ?? null;
        $rawServer = $headers['server'] ?? null;
        if (! $server && $cdn && $rawServer && stripos($rawServer, explode(' ', $cdn)[0]) !== false) {
            $rawServer = null;   // "Server: cloudflare" names the CDN, not the web server behind it
        }

        return [
            'url' => $url,
            'platform' => $pick('platform'),
            'languages' => ['server' => array_column([...$pick('language'), ...$pick('database')], 'name'), 'client' => $client],
            'frontend' => $pick('frontend'),
            'server' => $server ? trim($server['name'].' '.($server['version'] ?? '')) : $rawServer,
            'powered_by' => $headers['x-powered-by'] ?? null,
            'generator' => $generator ?: null,
            'cdn' => $cdn,
            'hosting' => self::hosting((string) parse_url($url, PHP_URL_HOST), $cdn),
        ];
    }

    private static function matches(array $t, array $headers, array $cookies, string $generator, string $html): bool
    {
        if (isset($t['generator']) && $generator !== '' && preg_match('/'.$t['generator'].'/i', $generator)) {
            return true;
        }
        foreach ($t['header'] ?? [] as $name => $pattern) {
            if (isset($headers[$name]) && ($pattern === '' || preg_match('/'.$pattern.'/i', $headers[$name]))) {
                return true;
            }
        }
        if (isset($t['cookie'])) {
            foreach ($cookies as $c) {
                if (preg_match($t['cookie'].'i', $c)) {
                    return true;
                }
            }
        }

        return isset($t['html']) && preg_match($t['html'].'i', $html);
    }

    private static function version(array $t, array $headers, string $generator, string $html): ?string
    {
        if (empty($t['version'])) {
            return null;
        }
        foreach ([$generator, $headers['x-powered-by'] ?? '', $headers['server'] ?? '', $html] as $haystack) {
            if ($haystack !== '' && preg_match('/'.$t['version'].'/i', $haystack, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The server's IP address and where it is: country, city and the network that owns it (hosting company).
     * Behind a CDN the address is the CDN's nearest edge, so the real server's country stays hidden.
     *
     * @return array{ip:?string, country:?string, country_code:?string, region:?string, city:?string, provider:?string, asn:?string, behind_cdn:bool, error:?string}|null
     */
    public static function hosting(string $host, ?string $cdn = null): ?array
    {
        if ($host === '' || ! config('webqa.geo_lookup', true)) {
            return null;
        }
        $ips = self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []);
        $ip = $ips[0] ?? null;
        $out = ['ip' => $ip, 'country' => null, 'country_code' => null, 'region' => null, 'city' => null,
            'provider' => null, 'asn' => null, 'behind_cdn' => $cdn !== null, 'error' => null];
        if (! $ip) {
            return ['error' => "Could not resolve {$host} to an IP address."] + $out;
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['error' => 'Private or local address — this site is not on the public internet.'] + $out;
        }
        try {
            $geo = Cache::remember("webqa-geo:{$ip}", now()->addDay(), function () use ($ip) {
                $res = Http::timeout(8)->connectTimeout(5)->acceptJson()->get(str_replace('{ip}', $ip, config('webqa.geo_url')));
                $json = $res->json();
                if (! $res->ok() || ! is_array($json) || ($json['success'] ?? true) === false || ($json['status'] ?? '') === 'fail') {
                    throw new \RuntimeException($json['message'] ?? "HTTP {$res->status()}");
                }

                return $json;
            });
        } catch (\Throwable $e) {
            return ['error' => 'Location lookup failed: '.$e->getMessage()] + $out;
        }

        // ipwho.is shape, with ip-api.com / ipinfo.io field names as fallbacks
        $conn = $geo['connection'] ?? [];

        return [
            'country' => $geo['country'] ?? null,
            'country_code' => $geo['country_code'] ?? $geo['countryCode'] ?? (isset($geo['country']) && strlen($geo['country']) === 2 ? $geo['country'] : null),
            'region' => $geo['region'] ?? $geo['regionName'] ?? null,
            'city' => $geo['city'] ?? null,
            'provider' => $conn['org'] ?? $conn['isp'] ?? $geo['org'] ?? $geo['isp'] ?? null,
            'asn' => isset($conn['asn']) ? 'AS'.$conn['asn'] : ($geo['as'] ?? null),
        ] + $out;
    }
}
