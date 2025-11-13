<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\Product\AddonController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Product\ProductOptionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Product\ProductVariantController;
use App\Http\Controllers\Product\AddonGroupController;
use App\Http\Controllers\Product\AddonGroupOptionController;
use App\Http\Controllers\Product\ProductOptionValueController;
use App\Http\Controllers\Auth\PasswordResetController;

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

    // Merchant catalog
    Route::get('merchants/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
        ->name('merchants.products');
});

// ============================================================
// AUTH ROUTES (Public + Protected)
// ============================================================
Route::prefix('auth')->name('auth.')->group(function () {
    // Public auth endpoints
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('reset-password');

    // Email verification
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    // Protected auth endpoints
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
        // Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });
});

// ============================================================
// PROTECTED ROUTES (require auth + verified email)
// ============================================================
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
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

        // Addons (merchant-level)
        Route::prefix('addons')->name('addons.')->group(function () {
            Route::get('/', [AddonController::class, 'index'])->name('index');
            Route::post('/', [AddonController::class, 'store'])->name('store');
            Route::patch('{addon}', [AddonController::class, 'update'])->name('update');
            Route::delete('{addon}', [AddonController::class, 'destroy'])->name('destroy');
        });

        // Products (main resource)
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::get('{product}', [ProductController::class, 'show'])->name('show');
            Route::put('{product}', [ProductController::class, 'update'])->name('update');
            Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');

            // Product status shortcuts
            Route::patch('{product}/publish', [ProductController::class, 'publish'])->name('publish');
            Route::patch('{product}/archive', [ProductController::class, 'archive'])->name('archive');

            // Product utilities
            Route::get('{product}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->name('combinations-count');

            // Nested resources under product
            Route::prefix('{product}')->group(function () {

                // Product Images
                Route::prefix('images')->name('images.')->group(function () {
                    Route::post('/', [ProductController::class, 'storeImage'])->name('store');
                    Route::patch('reorder', [ProductController::class, 'reorderImages'])->name('reorder');
                    Route::patch('{image}/cover', [ProductController::class, 'setCoverImage'])->name('cover');
                    Route::delete('{image}', [ProductController::class, 'destroyImage'])->name('destroy');
                });

                // Product Options
                Route::prefix('options')->name('options.')->group(function () {
                    Route::get('/', [ProductOptionController::class, 'index'])->name('index');
                    Route::post('/', [ProductOptionController::class, 'store'])->name('store');
                    Route::put('{option}', [ProductOptionController::class, 'update'])->name('update');
                    Route::delete('{option}', [ProductOptionController::class, 'destroy'])->name('destroy');

                    // Option Values
                    Route::prefix('{option}/values')->name('values.')->group(function () {
                        Route::post('/', [ProductOptionValueController::class, 'store'])->name('store');
                        Route::put('{value}', [ProductOptionValueController::class, 'update'])->name('update');
                        Route::delete('{value}', [ProductOptionValueController::class, 'destroy'])->name('destroy');

                        // Option Value Images
                        Route::post('{value}/image', [ProductOptionValueController::class, 'storeImage'])
                            ->name('image.store');
                        Route::delete('{value}/image', [ProductOptionValueController::class, 'destroyImage'])
                            ->name('image.destroy');
                    });
                });

                // Product Variants
                Route::prefix('variants')->name('variants.')->group(function () {
                    Route::get('/', [ProductVariantController::class, 'index'])->name('index');
                    Route::post('/', [ProductVariantController::class, 'store'])->name('store');
                    Route::patch('{variant}', [ProductVariantController::class, 'update'])->name('update');
                    Route::delete('{variant}', [ProductVariantController::class, 'destroy'])->name('destroy');
                    Route::patch('{variant}/option-values', [ProductVariantController::class, 'updateOptionValues'])
                        ->name('option-values.update');
                });

                // Product Addon Groups
                Route::prefix('addon-groups')->name('addon-groups.')->group(function () {
                    Route::get('/', [AddonGroupController::class, 'index'])->name('index');
                    Route::post('/', [AddonGroupController::class, 'store'])->name('store');
                    Route::patch('{group}', [AddonGroupController::class, 'update'])->name('update');
                    Route::delete('{group}', [AddonGroupController::class, 'destroy'])->name('destroy');

                    // Addon Group Options
                    Route::prefix('{group}/options')->name('options.')->group(function () {
                        Route::post('/', [AddonGroupOptionController::class, 'store'])->name('store');
                        Route::patch('{option}', [AddonGroupOptionController::class, 'update'])->name('update');
                        Route::delete('{option}', [AddonGroupOptionController::class, 'destroy'])->name('destroy');
                    });
                });
            });
        });
    });

    // ADMIN ROLE: Approve/reject merchants
    Route::middleware('role:admin')->prefix('merchants')->name('merchants.')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve'])->name('approve');
        Route::post('{merchant}/reject', [MerchantController::class, 'reject'])->name('reject');
    });
});