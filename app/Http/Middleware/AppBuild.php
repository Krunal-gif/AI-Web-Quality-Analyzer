<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags API responses with the current frontend build, so a tab opened before `npm run build` can tell it's out of
 * date (the single-page app never reloads itself) and offer a reload.
 */
class AppBuild
{
    public static function id(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? substr(md5_file($manifest), 0, 12) : 'dev';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('api/*')) {
            $response->headers->set('X-WebQA-Build', self::id());
        }

        return $response;
    }
}
