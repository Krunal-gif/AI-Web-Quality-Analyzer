<?php

namespace App\Analyzer;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A picture of the first screen (what a visitor sees before scrolling) of every analysed page, taken with headless
 * Chrome after the analysis appears. Like AiBatch it runs outside the request: `php artisan serve` starts
 * webqa:screenshots in the background; PHP-FPM runs it after the response. The UI polls the analysis for progress.
 */
final class Screenshots
{
    public const WIDTH = 1366;

    public const HEIGHT = 768;

    private const MAX_PAGES = 30;

    private const SECONDS_PER_PAGE = 40;

    public static function enabled(): bool
    {
        return (bool) config('webqa.screenshots', true);
    }

    public static function chrome(): ?string
    {
        if ($configured = config('webqa.chrome')) {
            return is_executable($configured) ? $configured : null;
        }
        $finder = new ExecutableFinder;
        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'microsoft-edge', 'brave-browser'] as $name) {
            if ($path = $finder->find($name)) {
                return $path;
            }
        }
        foreach (['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** File name of a page's picture inside the analysis folder's shots/ directory. */
    public static function key(string $rel): string
    {
        return substr(sha1($rel), 0, 16).'.webp';
    }

    /** Queue pictures of the analysed pages (website analyses only). Returns false when nothing will be taken. */
    public static function start(Analysis $a): bool
    {
        $pages = array_values(array_filter($a->docs, fn (Document $d) => $d->kind === 'html' && $d->url));
        if ($a->target['kind'] !== 'url' || ! $pages || ! self::enabled()) {
            return false;
        }
        $chrome = self::chrome();
        $a->shots = ['running' => (bool) $chrome, 'done' => 0, 'total' => min(count($pages), self::MAX_PAGES), 'updated' => time(),
            'error' => $chrome ? null : 'Google Chrome or Chromium is not installed, so page pictures can\'t be taken.', 'items' => []];

        return (bool) $chrome;
    }

    public static function dispatch(string $id): void
    {
        if (function_exists('fastcgi_finish_request') || app()->runningUnitTests()) {
            app()->terminating(fn () => self::run($id));

            return;
        }
        exec(sprintf('%s %s webqa:screenshots %s > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY), escapeshellarg(base_path('artisan')), escapeshellarg($id)));
    }

    public static function run(string $id): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $a = Analysis::load($id);
        $chrome = self::chrome();
        $pages = array_slice(array_values(array_filter($a->docs, fn (Document $d) => $d->kind === 'html' && $d->url)), 0, self::MAX_PAGES);
        $dir = $a->dir().'/shots';
        @mkdir($dir, 0775, true);

        foreach ($pages as $doc) {
            $file = $dir.'/'.self::key($doc->rel);
            $error = $chrome ? self::take($chrome, $doc->url, $file) : 'Chrome not found';
            Analysis::mutate($id, function (Analysis $a) use ($doc, $error) {
                $a->shots['items'][$doc->rel] = ['ok' => $error === null, 'error' => $error];
                $a->shots['done']++;
                $a->shots['updated'] = time();
            });
        }
        Analysis::mutate($id, function (Analysis $a) {
            $a->shots['running'] = false;
            $a->shots['updated'] = time();
        });
    }

    /** Take one picture; returns an error message, or null when the file was written. */
    public static function take(string $chrome, string $url, string $file): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return 'Only web pages can be pictured.';
        }
        // a throw-away browser profile each time: no cookies, logins or history from anything else
        $profile = storage_path('app/webqa/tmp/chrome-'.bin2hex(random_bytes(6)));
        $process = new Process([$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--mute-audio', '--no-first-run',
            '--no-default-browser-check', '--disable-extensions', '--disable-sync', '--disable-background-networking',
            '--user-data-dir='.$profile, '--window-size='.self::WIDTH.','.self::HEIGHT, '--virtual-time-budget=6000',
            '--screenshot='.$file, $url]);
        $process->setTimeout(self::SECONDS_PER_PAGE);
        try {
            @unlink($file);
            $process->run();
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'timed out') ? 'The page took too long to draw.' : 'Chrome failed: '.$e->getMessage();
        } finally {
            Analysis::removeDir($profile);
        }

        return is_file($file) && filesize($file) > 0 ? null : 'Chrome could not draw this page.';
    }
}
