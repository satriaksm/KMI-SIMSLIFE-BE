<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'audit' => \App\Http\Middleware\AuditLogMiddleware::class,
            'sanitize' => \App\Http\Middleware\SanitizeInputMiddleware::class,
        ]);

        // Global API middleware
        $middleware->api(prepend: [
            HandleCors::class,
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
        ]);

        // Apply sanitization to all routes except file uploads
        $middleware->append(\App\Http\Middleware\SanitizeInputMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->withProviders([
        App\Providers\AuthServiceProvider::class,
    ])->create();
