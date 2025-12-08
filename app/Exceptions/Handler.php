<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render exception sebagai JSON untuk API
     */
    public function render($request, Throwable $e)
    {
        // Jika request ke /api, paksa return JSON
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->renderJsonException($request, $e);
        }

        return parent::render($request, $e);
    }

    private function renderJsonException($request, Throwable $e)
    {
        // ValidationException (422)
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        }

        // AuthenticationException (401)
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'message' => 'Tidak terautentikasi.',
            ], 401);
        }

        // AuthorizationException (403)
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Aksi tidak diizinkan.',
            ], 403);
        }

        // ModelNotFoundException (404)
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'message' => 'Resource tidak ditemukan.',
            ], 404);
        }

        // NotFoundHttpException (404)
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'message' => 'Endpoint tidak ditemukan.',
            ], 404);
        }

        // Generic Exception (500)
        $statusCode = $e instanceof HttpExceptionInterface
            ? $e->getStatusCode()
            : 500;

        return response()->json([
            'message' => $e->getMessage() ?: 'Terjadi kesalahan server.',
            'error' => config('app.debug') ? [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ] : null,
        ], $statusCode);
    }
}