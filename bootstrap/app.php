<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AllowOptions;

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
        $middleware->append([
            AllowOptions::class,
            HandleCors::class,
        ]);

        // Enable Sanctum SPA (cookie-based) authentication for API routes.
        // NOTE: Do not prepend CSRF middleware to the API group; Sanctum's stateful stack
        // already wires the correct order (cookies -> session -> CSRF).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->withProviders([
            App\Providers\AuthServiceProvider::class,
        ])->create();
