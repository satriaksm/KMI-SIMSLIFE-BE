<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;

// Health check
Route::get('/', fn() => response()->json(['status' => 'API is running']));

// ============================================================
// PUBLIC ROUTES (No authentication required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // Public Products (e-commerce)
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');
        Route::get('/{slug}', [ProductController::class, 'publicShow'])->name('show');
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])->name('variant');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

    // Merchant catalog
    Route::get('merchants/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
        ->name('merchants.products');

});
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])
        ->name('images.show');
});
// ============================================================
// AUTH ROUTES (Public + Protected)
// ============================================================
Route::prefix('auth')->group(function () {
    // Public auth endpoints
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('reset-password');

    // Email verification
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    // Protected auth endpoints (require session + sanctum)
    // IMPORTANT: add 'web' so Sanctum can read session cookies
    Route::middleware(['web', 'auth:sanctum'])->group(function () {
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('api.verification.send');
    });
});

// ============================================================
// PROTECTED ROUTES (require auth + verified email)
// ============================================================
// Wrap protected routes with 'web' so session/cookie middlewares are available,
// then apply 'auth:sanctum' and other guards.
Route::middleware(['web', 'auth:sanctum', 'verified'])->group(function () {

    // Locations
    Route::prefix('locations')->controller(LocationController::class)->group(function () {
        Route::get('provinces', 'provinces');
        Route::get('cities/{provinceId}', 'cities');
        Route::get('districts/{cityId}', 'districts');
        Route::get('villages/{districtId}', 'villages');
    });

    Route::get('segmentations', [SegmentationController::class, 'index'])->name('segmentations.index');

    // CUSTOMER ROLE: Register merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ROLE: Manage products, addons
    Route::middleware('role:umkm-owner')->group(function () {

        // Products (main resource)
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::get('{product}', [ProductController::class, 'show'])->name('show');
            Route::put('{product}', [ProductController::class, 'update'])->name('update');
            // ✅ UPDATED: PATCH untuk update (termasuk status)
            Route::patch('{product}', [ProductController::class, 'update'])->name('update.patch');
            Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');

            // ✅ NEW: Endpoint khusus untuk update status
            Route::patch('{product}/status', [ProductController::class, 'updateStatus'])->name('update-status');

            // Product utilities
            Route::get('{product}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->name('combinations-count');

            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);
        });
    });

    // ADMIN ROLE: Approve/reject merchants
    Route::middleware('role:admin')->prefix('merchants')->name('merchants.')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve'])->name('approve');
        Route::post('{merchant}/reject', [MerchantController::class, 'reject'])->name('reject');
    });
});