<?php

namespace App\Analyzer;

/**
 * Runs "Explain & fix with AI" for a list of issues, one at a time, outside the HTTP request.
 *
 * Under `php artisan serve` the work runs in its own background process (webqa:ai-batch), so the
 * browser gets its answer at once and can keep polling. Under PHP-FPM (and in tests) it runs
 * right after the response has been sent instead.
 */
final class AiBatch
{
    /** @param  string[]  $queue  issue ids */
    public static function dispatch(string $id, array $queue): void
    {
        if (function_exists('fastcgi_finish_request') || app()->runningUnitTests()) {
            app()->terminating(fn () => self::run($id, $queue));

            return;
        }
        exec(sprintf('%s %s webqa:ai-batch %s %s > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY), escapeshellarg(base_path('artisan')), escapeshellarg($id),
            implode(' ', array_map('escapeshellarg', $queue))));
    }

    /** @param  string[]  $queue  issue ids */
    public static function run(string $id, array $queue): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        $ollama = Ollama::fromSettings();
        $offline = false;

        foreach ($queue as $issueId) {
            // read the issue (and the cancel flag) without holding the lock during the slow model call
            $a = Analysis::load($id);
            $item = $a->issue($issueId);
            if (! $item || $a->batch['cancel'] || $offline) {
                break;
            }
            Analysis::mutate($id, function (Analysis $a) use ($issueId) {
                $a->batch['current'] = $issueId;
                $a->batch['updated'] = time();
            });

            $result = $ollama->review($item, $a->doc($item->file));

            Analysis::mutate($id, function (Analysis $a) use ($issueId, $result, $ollama, &$offline) {
                $a->storeAi($a->issue($issueId), $result, $ollama->model);
                $a->batch['done']++;
                $a->batch['updated'] = time();
                if (! empty($result['error'])) {
                    $a->batch['errors']++;
                    $offline = str_contains($result['error'], 'Cannot reach');
                }
            });
        }

        Analysis::mutate($id, function (Analysis $a) {
            foreach ($a->issues as $i) {
                $i->aiPending = false;
            }
            $a->batch['running'] = false;
            $a->batch['current'] = null;
            $a->batch['updated'] = time();
        });
    }
}
