<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
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
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->statefulApi();
        // 🆕 ADDED from feat/rating-system: Global middleware untuk OPTIONS request
        $middleware->append([
            AllowOptions::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->withProviders([
            App\Providers\AuthServiceProvider::class,
        ])->create();