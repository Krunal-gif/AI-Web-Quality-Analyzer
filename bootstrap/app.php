<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware()   // registers Laravel's standard `web` middleware (sessions, CSRF protection)
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
