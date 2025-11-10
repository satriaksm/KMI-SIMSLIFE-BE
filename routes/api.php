<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\CommunityPostController;

Route::get('/', fn() => response()->json(['status' => 'API & CI/CD are running']));

// Auth routes (JSON only)
Route::prefix('auth')->group(function () {
    // Public
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
        // Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });
});

// Protected API
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // Locations
    Route::prefix('locations')->controller(LocationController::class)->group(function () {
        Route::get('provinces', 'provinces');
        Route::get('cities/{provinceId}', 'cities');
        Route::get('districts/{cityId}', 'districts');
        Route::get('villages/{districtId}', 'villages');
    });

    // Segmentations
    Route::get('/segmentations', [SegmentationController::class, 'index'])->name('segmentations.index');

    // Customer: register merchant (pending)
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
        // alias path jika FE memanggil /auth/merchant-register
        Route::post('/auth/merchant-register', [MerchantController::class, 'register']);
    });

    // Admin: approve/reject
    Route::middleware('role:admin')->group(function () {
        Route::post('/merchants/{merchant}/approve', [MerchantController::class, 'approve'])->name('merchants.approve');
        Route::post('/merchants/{merchant}/reject', [MerchantController::class, 'reject'])->name('merchants.reject');
    });

    // Community Posts
    Route::prefix('community')->group(function () {
        Route::get('/posts', [CommunityPostController::class, 'index']);
        Route::get('/posts/popular', [CommunityPostController::class, 'popular']);
        Route::post('/posts', [CommunityPostController::class, 'store']);
        Route::get('/posts/{slug}', [CommunityPostController::class, 'show']);
        Route::put('/posts/{id}', [CommunityPostController::class, 'update']);
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy']);
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts']);
    });

});
