<?php

namespace App\Analyzer;

use App\Analyzer\Html\HtmlParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Load analysis targets: a website URL (optionally crawled), a local project folder, or a single file.
 *
 * @phpstan-type Target array{kind:string, label:string, root:?string, docs:Document[], site:?array}
 */
final class SourceLoader
{
    public const EXTENSIONS = ['html' => 'html', 'htm' => 'html', 'css' => 'css', 'js' => 'js', 'mjs' => 'js',
        'cjs' => 'js', 'json' => 'json', 'py' => 'python', 'php' => 'php'];

    private const SKIP_DIRS = ['node_modules', '.git', 'vendor', 'dist', 'build', '__pycache__', '.venv', 'venv',
        '.next', '.nuxt', '.cache', 'coverage', 'webqa-output', '.idea', '.vscode', 'storage', 'bootstrap'];

    private const SKIP_SUFFIXES = ['.min.js', '.min.css', '.map', 'package-lock.json', 'composer.lock'];

    private const MAX_FILES = 400;

    private const MAX_BYTES = 1_500_000;

    /** Pages analysed for a sitemap target when no page count is given. */
    public const SITE_PAGES = 50;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; WebQA-Analyzer/1.0; +local)';

    private const NON_PAGE_EXT = '/\.(pdf|jpe?g|png|gif|svg|webp|avif|zip|mp4|mp3|css|js|xml|json|ico|woff2?|ttf|docx?|xlsx?)$/i';

    /** @return array{kind:string, label:string, root:?string, docs:Document[], site:?array} */
    public static function load(string $target, int $maxPages = 1): array
    {
        $target = trim($target);
        if (preg_match('#^https?://#i', $target)) {
            return self::loadUrl($target, $maxPages);
        }
        if (preg_match('#^[\w.-]+\.[a-z]{2,}(/.*)?$#i', $target) && ! file_exists($target)) {
            return self::loadUrl('https://'.$target, $maxPages);
        }
        $path = str_starts_with($target, '~/') ? (getenv('HOME') ?: '').substr($target, 1) : $target;
        $real = realpath($path);
        if ($real !== false && is_dir($real)) {
            return self::loadDir($real);
        }
        if ($real !== false && is_file($real)) {
            $doc = self::readFile($real, dirname($real));
            if ($doc === null) {
                throw new \InvalidArgumentException("Unsupported file type: {$target} (supported: ".implode(', ', array_keys(self::EXTENSIONS)).')');
            }

            return ['kind' => 'file', 'label' => $real, 'root' => dirname($real), 'docs' => [$doc], 'site' => null];
        }
        throw new \InvalidArgumentException("Not a URL, folder or file: {$target}");
    }

    // ---------------------------------------------------------------------------- local files

    private static function readFile(string $path, string $root): ?Document
    {
        $name = strtolower(basename($path));
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (! isset(self::EXTENSIONS[$ext])) {
            return null;
        }
        foreach (self::SKIP_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return null;
            }
        }
        if (filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $raw = file_get_contents($path);
        $encoding = 'UTF-8';
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $encoding = 'ISO-8859-1';
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }
        $rel = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(rtrim($root, '/')))), '/');

        return new Document($rel, self::EXTENSIONS[$ext], $raw, $path, null, $encoding);
    }

    private static function loadDir(string $root): array
    {
        $docs = [];
        $walk = function (string $dir) use (&$walk, &$docs, $root) {
            $entries = scandir($dir) ?: [];
            sort($entries, SORT_STRING);
            $dirs = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || count($docs) >= self::MAX_FILES) {
                    continue;
                }
                $full = $dir.'/'.$entry;
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    if (! in_array($entry, self::SKIP_DIRS, true) && ! str_starts_with($entry, '.')) {
                        $dirs[] = $full;
                    }
                } elseif ($doc = self::readFile($full, $root)) {
                    $docs[] = $doc;
                }
            }
            foreach ($dirs as $sub) {
                $walk($sub);
            }
        };
        $walk($root);
        if (! $docs) {
            throw new \InvalidArgumentException("No supported files found in {$root}");
        }
        usort($docs, fn (Document $a, Document $b) => [$a->kind !== 'html', $a->rel] <=> [$b->kind !== 'html', $b->rel]);

        return ['kind' => 'dir', 'label' => $root, 'root' => $root, 'docs' => $docs, 'site' => null];
    }

    // ------------------------------------------------------------------------------- websites

    public static function fetch(string $url, int $timeout = 20): array
    {
        $started = microtime(true);
        $get = fn (array $options = []) => Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])
            ->timeout($timeout)->connectTimeout(10)->withOptions(['allow_redirects' => ['max' => 5]] + $options)->get($url);
        try {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $res = isset(self::$chainFixed[$host]) ? $get(['verify' => self::$chainFixed[$host]]) : $get();
        } catch (ConnectionException $e) {
            // cURL 60: the server left out its intermediate certificate. Browsers fetch it themselves; do the same.
            $bundle = str_contains($e->getMessage(), 'cURL error 60') ? self::completeChain($url) : null;
            if (! $bundle) {
                throw new \InvalidArgumentException("Could not fetch {$url}: ".$e->getMessage(), 0, $e);
            }
            try {
                $res = $get(['verify' => $bundle]);
            } catch (ConnectionException $e) {
                throw new \InvalidArgumentException("Could not fetch {$url}: ".$e->getMessage(), 0, $e);
            }
        }
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $body = substr($res->body(), 0, self::MAX_BYTES);
        $contentType = $res->header('Content-Type');
        $charset = preg_match('/charset=([\w-]+)/i', $contentType, $m) ? $m[1] : null;
        if (! $charset && preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
            $charset = $m[1];
        }
        $charset = $charset ?: 'UTF-8';
        if (strtoupper($charset) !== 'UTF-8' || ! mb_check_encoding($body, 'UTF-8')) {
            try {
                $body = mb_convert_encoding($body, 'UTF-8', strtoupper($charset) === 'UTF-8' ? 'ISO-8859-1' : $charset);
            } catch (\ValueError) {
                $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
            }
        }

        return ['url' => $url, 'final_url' => (string) ($res->effectiveUri() ?? $url), 'status' => $res->status(),
            'elapsed_ms' => $elapsed, 'content_type' => $contentType, 'text' => $body, 'bytes' => strlen($res->body()),
            'headers' => $res->headers()];
    }

    private static function relFor(string $url): string
    {
        $p = parse_url($url);
        $path = $p['path'] ?? '/';
        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index.html';
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === '') {
            $path .= '.html';
        }
        $host = ($p['host'] ?? '').(isset($p['port']) ? '-'.$p['port'] : '');
        $rel = $host.$path;
        if (! empty($p['query'])) {
            $rel .= '__'.substr(preg_replace('/[^\w-]+/', '_', $p['query']), 0, 40);
        }

        return $rel;
    }

    /** @var array<string,string> host => CA bundle (system roots + the intermediate that host forgot to send) */
    private static array $chainFixed = [];

    /**
     * Download the intermediate certificate named in the site's certificate (its "CA Issuers" address) and return a
     * CA bundle that includes it — what browsers do for a server that sends an incomplete chain. The intermediate is
     * only used if a trusted root on this computer signed it and it signed the site's certificate, so a fake server
     * can't slip in its own authority this way.
     */
    private static function completeChain(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $roots = self::systemCaFile();
        if ($host === '' || ! $roots) {
            return null;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'peer_name' => $host, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        $leaf = $sock ? (stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? null) : null;
        if ($sock) {
            fclose($sock);
        }
        $aia = $leaf ? (openssl_x509_parse($leaf)['extensions']['authorityInfoAccess'] ?? '') : '';
        if (! preg_match('#CA Issuers - URI:(https?://\S+)#', $aia, $m)) {
            return null;
        }
        try {
            $raw = Http::timeout(10)->get($m[1])->body();
        } catch (ConnectionException) {
            return null;
        }
        $pem = str_contains($raw, '-----BEGIN CERTIFICATE-----') ? $raw
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($raw), 64, "\n")."-----END CERTIFICATE-----\n";
        $intermediate = @openssl_x509_read($pem);
        $key = $intermediate ? openssl_pkey_get_public($intermediate) : false;
        if (! $key || openssl_x509_verify($leaf, $key) !== 1 || openssl_x509_checkpurpose($intermediate, X509_PURPOSE_ANY, [$roots]) !== true) {
            return null;
        }
        openssl_x509_export($intermediate, $pem);
        $bundle = storage_path('app/webqa/ca/'.sha1($pem).'.pem');
        if (! is_file($bundle)) {
            @mkdir(dirname($bundle), 0775, true);
            file_put_contents($bundle, file_get_contents($roots)."\n".$pem);
        }

        return self::$chainFixed[$host] = $bundle;
    }

    private static function systemCaFile(): ?string
    {
        $locations = openssl_get_cert_locations();
        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile'), getenv('SSL_CERT_FILE'), $locations['default_cert_file'] ?? null,
            '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $file) {
            if ($file && is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /** True when this host sent an incomplete certificate chain that WebQA had to complete itself. */
    public static function chainWasIncomplete(string $host): bool
    {
        return isset(self::$chainFixed[strtolower($host)]);
    }

    private static function stripFragment(string $url): string
    {
        return explode('#', $url, 2)[0];
    }

    /**
     * `php artisan serve` with one worker is busy running this request, so it can't also answer a fetch of itself —
     * cURL would just hang until the timeout. When the target is this app, ping its /up route first and say so quickly.
     * (PHP_CLI_SERVER_WORKERS can't be trusted here: Laravel loads it from .env even when serve ignored it.)
     */
    private static function guardSelfFetch(string $url): void
    {
        if (PHP_SAPI !== 'cli-server') {
            return;
        }
        $loopback = fn (string $h) => in_array(strtolower(trim($h, '[]')), ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $serverHost = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($port !== (int) ($_SERVER['SERVER_PORT'] ?? 0)
            || (strcasecmp($host, $serverHost) !== 0 && ! ($loopback($host) && $loopback($serverHost)))) {
            return;
        }
        try {
            Http::timeout(3)->connectTimeout(2)->get(parse_url($url, PHP_URL_SCHEME)."://{$host}:{$port}/up");

            return;   // another worker answered — the real fetch will work
        } catch (ConnectionException) {
        }

        throw new \InvalidArgumentException("{$url} is this WebQA app, and php artisan serve is running with a single worker, "
            .'so it can\'t answer a request to itself while it runs the analysis. Restart it with `php artisan serve --no-reload` '
            .'(PHP_CLI_SERVER_WORKERS=4 in .env then gives it several workers), or analyse the project folder instead.');
    }

    /** "www.example.com" and "example.com" are the same site (sitemaps often use the other form). */
    private static function sameHost(?string $a, ?string $b): bool
    {
        $strip = fn (?string $h) => preg_replace('/^www\./', '', strtolower((string) $h));

        return $strip($a) !== '' && $strip($a) === $strip($b);
    }

    /**
     * Crawl a website. With more than one page, the sitemap is read first and every page it lists is analysed in
     * sitemap order; links found on those pages fill any remaining budget. A sitemap URL as the target (…/sitemap.xml)
     * analyses exactly the pages it lists.
     */
    private static function loadUrl(string $start, int $maxPages): array
    {
        $start = self::stripFragment($start);
        self::guardSelfFetch($start);
        $p = parse_url($start);
        $host = $p['host'] ?? '';
        $origin = $p['scheme'].'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '');
        $fromSitemap = (bool) preg_match('#\.xml(\.gz)?$#i', $p['path'] ?? '');
        // a sitemap target asks for its pages, so a page budget of 1 (the single-page default) means "the usual site scan"
        $maxPages = $fromSitemap && $maxPages <= 1 ? self::SITE_PAGES : max(1, $maxPages);
        $site = $fromSitemap || $maxPages > 1 ? self::siteFiles($origin, $fromSitemap ? $start : null, $maxPages) : [];
        if ($fromSitemap && ! $site['sitemap']) {
            throw new \InvalidArgumentException("{$start} is not a readable XML sitemap (expected <urlset> or <sitemapindex>).");
        }

        $queue = $fromSitemap ? [] : [$start];
        $source = $fromSitemap ? [] : [$start => 'start'];
        foreach ($site['sitemap_pages'] ?? [] as $url) {
            if (! isset($source[$url])) {
                $source[$url] = 'sitemap';
                $queue[] = $url;
            }
        }
        if (! $queue) {
            throw new \InvalidArgumentException("The sitemap {$start} lists no pages on {$host}.");
        }
        $seen = $source;
        $seenRels = [];
        $seenBodies = [];
        $docs = [];
        $pages = [];
        $profiled = null;

        while ($queue && count($docs) < $maxPages) {
            $url = array_shift($queue);
            try {
                $res = self::fetch($url);
            } catch (\InvalidArgumentException $e) {
                if (! $docs && $url === $start) {
                    throw $e;
                }
                $pages[] = ['url' => $url, 'rel' => self::relFor($url), 'status' => 0, 'elapsed_ms' => 0, 'bytes' => 0,
                    'source' => $source[$url] ?? 'link', 'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage()];

                continue;
            }
            $rel = self::relFor($res['final_url']);
            $hash = md5($res['text']);
            if (isset($seenRels[$rel]) || ($res['status'] < 400 && isset($seenBodies[$hash]))) {
                continue;   // e.g. "/" and "/index.html"
            }
            $seenRels[$rel] = true;
            if ($res['status'] < 400) {
                $seenBodies[$hash] = true;
            }
            $pages[] = ['url' => $url, 'rel' => $rel, 'status' => $res['status'], 'elapsed_ms' => $res['elapsed_ms'], 'bytes' => $res['bytes'],
                'source' => $source[$url] ?? 'link'];
            if ($res['status'] >= 400) {
                continue;   // reported by the site rules; the error page itself isn't analysed
            }
            if (! str_contains(strtolower($res['content_type']), 'html') && ! str_starts_with(ltrim($res['text']), '<')) {
                continue;
            }
            $docs[] = new Document($rel, 'html', $res['text'], null, $res['final_url']);
            $profiled ??= $res;   // the site profile comes from the first page analysed (normally the home page)
            // a sitemap target is exactly its listed pages; otherwise links fill whatever budget the sitemap left
            if (! $fromSitemap && count($docs) + count($queue) < $maxPages) {
                foreach (HtmlParser::parse($res['text'])->findAll('a') as $a) {
                    $link = self::stripFragment(self::resolve($res['final_url'], $a->get('href')));
                    $lp = parse_url($link);
                    if (in_array($lp['scheme'] ?? '', ['http', 'https'], true) && self::sameHost($lp['host'] ?? '', $host)
                        && ! isset($seen[$link]) && ! preg_match(self::NON_PAGE_EXT, $lp['path'] ?? '')) {
                        $seen[$link] = true;
                        $queue[] = $link;
                    }
                }
            }
        }
        if (! $docs) {
            throw new \InvalidArgumentException($fromSitemap ? "None of the pages in {$start} returned HTML." : "{$start} did not return an HTML page");
        }

        [$assetDocs, $assets] = self::loadAssets($docs, $host);
        array_push($docs, ...$assetDocs);

        try {
            $profile = SiteProfile::build($profiled['final_url'], $profiled['headers'] ?? [], $profiled['text']);
        } catch (\Throwable $e) {   // the profile is extra information; it must never stop the analysis
            report($e);
            $profile = null;
        }

        return ['kind' => 'url', 'label' => $start, 'root' => null, 'docs' => $docs,
            // robots.txt and the sitemap belong to the whole site, so they're only checked when crawling several pages
            'site' => ['origin' => $origin, 'pages' => $pages, ...array_diff_key($site, ['sitemap_pages' => 1]),
                'tls_chain_incomplete' => self::chainWasIncomplete($host), 'profile' => $profile,
                'security' => self::securityHeaders($profiled), 'assets' => $assets]];
    }

    private const MAX_ASSETS = 80;

    private const MAX_ASSET_CODE_FILES = 25;

    /** Time budget for checking a site's files. */
    private const ASSET_SECONDS = 45;

    /** Tags and attributes whose files a page needs to display and work. */
    public const ASSET_TAGS = [['script', 'src', 'script'], ['link', 'href', 'stylesheet'], ['img', 'src', 'image'], ['source', 'src', 'image']];

    /**
     * Request every script, stylesheet and image the analysed pages use (any host), so broken references can be
     * reported, and keep the site's own JavaScript and CSS as documents so their code is analysed too.
     *
     * @param  Document[]  $docs
     * @return array{0: Document[], 1: array<string, array{status:int, type:string}>}
     */
    private static function loadAssets(array $docs, string $host): array
    {
        $wanted = [];
        foreach ($docs as $doc) {
            $tree = HtmlParser::parse($doc->text);
            foreach (self::ASSET_TAGS as [$tag, $attr, $type]) {
                foreach ($tree->findAll($tag) as $node) {
                    $raw = trim($node->get($attr));
                    if ($raw === '' || ($tag === 'link' && ! preg_match('/\bstylesheet\b/i', $node->get('rel')))) {
                        continue;
                    }
                    $url = self::stripFragment(self::resolve($doc->url ?? '', $raw));
                    if (preg_match('#^https?://#i', $url) && ! isset($wanted[$url])) {
                        $wanted[$url] = $type;
                    }
                }
            }
        }
        // code first (it's analysed too), then images
        uksort($wanted, fn ($a, $b) => [$wanted[$a] === 'image', $a] <=> [$wanted[$b] === 'image', $b]);
        $wanted = array_slice($wanted, 0, self::MAX_ASSETS, true);

        $assets = [];
        $codeDocs = [];
        $deadline = microtime(true) + self::ASSET_SECONDS;
        foreach (array_chunk(array_keys($wanted), 8) as $chunk) {
            if (microtime(true) > $deadline) {
                break;   // a slow server must not stall the analysis; files not checked are simply not reported
            }
            $responses = Http::pool(fn (Pool $pool) => array_map(fn (string $url) => $pool->as($url)
                ->withHeaders(['User-Agent' => self::USER_AGENT])->timeout(15)->connectTimeout(8)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->{$wanted[$url] === 'image' ? 'head' : 'get'}($url), $chunk));
            foreach ($chunk as $url) {
                $res = $responses[$url] ?? null;
                if ($res instanceof Response && in_array($res->status(), [403, 405, 501], true) && $wanted[$url] === 'image') {
                    try {   // some servers refuse HEAD; ask again with GET before calling it broken
                        $res = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(15)->get($url);
                    } catch (\Throwable) {
                    }
                }
                $status = $res instanceof Response ? $res->status() : 0;
                $assets[$url] = ['status' => $status, 'type' => $wanted[$url]];
                if ($status === 200 && $wanted[$url] !== 'image' && count($codeDocs) < self::MAX_ASSET_CODE_FILES
                    && self::sameHost(parse_url($url, PHP_URL_HOST), $host)) {
                    $body = $res->body();
                    $declared = $res->header('Content-Length');
                    if ($declared !== '' && $res->header('Content-Encoding') === '' && (int) $declared !== strlen($body)) {
                        continue;   // cut off in transfer: analysing half a file would report errors that aren't there
                    }
                    $kind = $wanted[$url] === 'script' ? 'js' : 'css';
                    $looksRight = $kind === 'js' ? ! str_starts_with(ltrim($body), '<') : ! preg_match('/^\s*<(!doctype|html)/i', $body);
                    if ($body !== '' && strlen($body) <= self::MAX_BYTES && $looksRight && mb_check_encoding($body, 'UTF-8')) {
                        $rel = self::relFor($url);
                        $rel = preg_replace('/\.html$/', '.'.$kind, $rel);   // "/app.js?v=3" keeps a sensible name
                        $codeDocs[$rel] = new Document($rel, $kind, $body, null, $url);
                    }
                }
            }
        }
        $taken = array_flip(array_map(fn (Document $d) => $d->rel, $docs));

        return [array_values(array_diff_key($codeDocs, $taken)), $assets];
    }

    private const SECURITY_HEADERS = ['strict-transport-security', 'content-security-policy', 'content-security-policy-report-only',
        'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'server', 'x-powered-by',
        'x-aspnet-version', 'x-generator'];

    /** The security-relevant headers and Set-Cookie values of a fetched page, for SiteRules::security(). */
    private static function securityHeaders(array $res): array
    {
        $headers = array_change_key_case($res['headers'] ?? []);

        return ['url' => $res['final_url'],
            'headers' => array_map(fn ($v) => implode(', ', (array) $v), array_intersect_key($headers, array_flip(self::SECURITY_HEADERS))),
            'cookies' => array_values((array) ($headers['set-cookie'] ?? []))];
    }

    /** Resolve a possibly-relative href against a base URL. */
    public static function resolve(string $base, string $href): string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return $href === '' ? $base : $href;
        }
        $b = parse_url($base);
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'].':'.$href;
        }
        if (str_starts_with($href, '#') || str_starts_with($href, '?')) {
            return explode($href[0], $base)[0].$href;
        }
        $path = str_starts_with($href, '/') ? $href : preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/').$href;
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            [$path, $query] = [substr($path, 0, $q), substr($path, $q)];
        }
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $resolved = implode('/', $segments);

        return $origin.(str_starts_with($resolved, '/') ? '' : '/').$resolved.$query;
    }

    /**
     * Check robots.txt and find the sitemap ($given, else robots.txt "Sitemap:", else the usual locations), then read
     * up to $limit page URLs from it — following a sitemap index into its child sitemaps.
     *
     * @return array{robots:bool, sitemap:bool, sitemap_url:?string, sitemap_total:int, sitemap_pages:string[]}
     */
    private static function siteFiles(string $origin, ?string $given = null, int $limit = 50): array
    {
        $robotsOk = false;
        $listed = [];
        try {
            $r = self::fetch($origin.'/robots.txt', 10);
            $robotsOk = $r['status'] === 200 && ! str_contains(strtolower(substr($r['text'], 0, 500)), '<html');
            if ($robotsOk && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $r['text'], $m)) {
                $listed = $m[1];
            }
        } catch (\InvalidArgumentException) {
        }
        $candidates = $given ? [$given] : [...$listed, $origin.'/sitemap.xml', $origin.'/sitemap_index.xml', $origin.'/wp-sitemap.xml'];
        foreach (array_unique($candidates) as $candidate) {
            $xml = self::fetchSitemap($candidate);
            if ($xml === null) {
                continue;
            }
            [$pages, $total] = self::sitemapPages($candidate, $xml, parse_url($origin, PHP_URL_HOST), $limit);

            return ['robots' => $robotsOk, 'sitemap' => true, 'sitemap_url' => $candidate, 'sitemap_total' => $total, 'sitemap_pages' => $pages];
        }

        return ['robots' => $robotsOk, 'sitemap' => false, 'sitemap_url' => null, 'sitemap_total' => 0, 'sitemap_pages' => []];
    }

    /** The sitemap's XML, or null when the URL isn't a readable sitemap. Handles .xml.gz files. */
    private static function fetchSitemap(string $url): ?string
    {
        try {
            $r = self::fetch($url, 15);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $text = $r['text'];
        if (str_starts_with($text, "\x1f\x8b")) {
            $text = @gzdecode($text) ?: '';
        }

        return $r['status'] === 200 && (str_contains($text, '<urlset') || str_contains($text, '<sitemapindex')) ? $text : null;
    }

    /**
     * Same-site page URLs listed in a sitemap, in sitemap order. A sitemap index is followed one level down (up to
     * 50 child sitemaps), stopping once $limit pages are collected.
     *
     * @return array{0:string[], 1:int} [pages (at most $limit), total pages found]
     */
    private static function sitemapPages(string $url, string $xml, string $host, int $limit, int $depth = 0): array
    {
        $locs = preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', $xml, $m)
            ? array_map(fn ($l) => self::stripFragment(html_entity_decode($l, ENT_QUOTES | ENT_XML1)), $m[1]) : [];
        $pages = [];
        $total = 0;
        if (str_contains($xml, '<sitemapindex')) {
            foreach (array_slice($locs, 0, 50) as $child) {
                if ($depth >= 2 || count($pages) >= $limit) {
                    break;
                }
                $childXml = self::fetchSitemap(self::resolve($url, $child));
                if ($childXml !== null) {
                    [$found, $n] = self::sitemapPages($child, $childXml, $host, $limit - count($pages), $depth + 1);
                    array_push($pages, ...$found);
                    $total += $n;
                }
            }

            return [array_values(array_unique($pages)), max($total, count($pages))];
        }
        foreach ($locs as $loc) {
            $lp = parse_url($loc);
            if (in_array($lp['scheme'] ?? '', ['http', 'https'], true) && self::sameHost($lp['host'] ?? '', $host)
                && ! preg_match(self::NON_PAGE_EXT, $lp['path'] ?? '')) {
                $total++;
                if (count($pages) < $limit) {
                    $pages[] = $loc;
                }
            }
        }

        return [array_values(array_unique($pages)), $total];
    }
}
