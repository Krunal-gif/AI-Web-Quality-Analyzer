<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    // Laravel's standard `web` middleware (sessions, CSRF protection), plus the frontend build tag on API responses
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [\App\Http\Middleware\AppBuild::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Analyzer errors are user-facing messages, not server crashes.
        $exceptions->render(function (\InvalidArgumentException|\DomainException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        $exceptions->render(function (\OutOfBoundsException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 404);
        });
    })->create();
