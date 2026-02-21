<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API routes in routes/api.php intentionally use `web` middleware for session auth
        // (CheckAuth relies on session state). Exempt these endpoints from CSRF verification
        // to avoid intermittent token mismatch for fetch/XHR calls.
        $middleware->validateCsrfTokens(except: [
            'api/upload',
            'api/documents',
            'api/documents/*',
            'api/github/callback',
            'api/github/upload-results',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
