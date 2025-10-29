<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;
use Illuminate\Container\Attributes\Auth;

// Debug
Route::get('/', function () {
    return response()->json(['status' => 'API is & CI/CD running']);
});

// Authencation Routes
Route::prefix('auth')->group(function () {
    // Public routes
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });

    Route::middleware(['auth:sanctum', 'role:customer'])->group(function () {
        Route::post('/merchant-register', [AuthController::class, 'registerMerchant'])->name('merchants.register');
    });
});

// Views Routes
Route::middleware(['auth:sanctum'])->group(function () {

});