<?php

use Illuminate\Support\Facades\Route;

// Controllers lama (Jasa / Promo / Orders)
use App\Http\Controllers\JasaController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\OrderController;

// Controllers baru
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

// ============================================================
// HEALTH CHECK
// ============================================================
Route::get('/', fn() => response()->json(['status' => 'API is running']));


// ============================================================
// PUBLIC ROUTES (No Auth Required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // Public Products
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');
        Route::get('/{slug}', [ProductController::class, 'publicShow'])->name('show');
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])->name('variant');
    });

    // Merchant Catalog
    Route::get('merchants/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
        ->name('merchants.products');
});


// ============================================================
// 🆕 PUBLIC API DARI SISTEM LAMA (JASA / PROMO / ORDER)
// ============================================================

// ---------- JASA ----------
Route::prefix('jasa')->group(function () {
    Route::get('/', [JasaController::class, 'index']);
    Route::get('/{id}', [JasaController::class, 'show']);
    Route::post('/', [JasaController::class, 'store']);
    Route::put('/{id}', [JasaController::class, 'update']);
    Route::delete('/{id}', [JasaController::class, 'destroy']);
});

// ---------- PROMO ----------
Route::prefix('promos')->group(function () {
    Route::get('/', [PromoController::class, 'index']);
    Route::get('/{id}', [PromoController::class, 'show'])->name('promos.show');
    Route::post('/', [PromoController::class, 'store']);
    Route::delete('/{id}', [PromoController::class, 'destroy']);
});

// ---------- ORDERS ----------
Route::prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::get('/{id}', [OrderController::class, 'show']);
    Route::post('/', [OrderController::class, 'store']);
});


// ============================================================
// AUTH ROUTES
// ============================================================
Route::prefix('auth')->name('auth.')->group(function () {

    // Public auth
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('reset-password');

    // Email verification
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    // Protected auth
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});


// ============================================================
// PROTECTED ROUTES (AUTH + VERIFIED)
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

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // Addons
        Route::prefix('addons')->name('addons.')->group(function () {
            Route::get('/', [AddonController::class, 'index']);
            Route::post('/', [AddonController::class, 'store']);
            Route::patch('{addon}', [AddonController::class, 'update']);
            Route::delete('{addon}', [AddonController::class, 'destroy']);
        });

        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {

            // CRUD
            Route::get('/', [ProductController::class, 'index']);
            Route::post('/', [ProductController::class, 'store']);
            Route::get('{product}', [ProductController::class, 'show']);
            Route::put('{product}', [ProductController::class, 'update']);
            Route::delete('{product}', [ProductController::class, 'destroy']);

            // Publish / Archive
            Route::patch('{product}/publish', [ProductController::class, 'publish']);
            Route::patch('{product}/archive', [ProductController::class, 'archive']);

            // Variant combination counter
            Route::get('{product}/combinations-count', [ProductController::class, 'getCombinationCount']);

            // Nested: Images, Options, Variants, Addon Groups
            Route::prefix('{product}')->group(function () {

                // Images
                Route::prefix('images')->group(function () {
                    Route::post('/', [ProductController::class, 'storeImage']);
                    Route::patch('reorder', [ProductController::class, 'reorderImages']);
                    Route::patch('{image}/cover', [ProductController::class, 'setCoverImage']);
                    Route::delete('{image}', [ProductController::class, 'destroyImage']);
                });

                // Options
                Route::prefix('options')->group(function () {
                    Route::get('/', [ProductOptionController::class, 'index']);
                    Route::post('/', [ProductOptionController::class, 'store']);
                    Route::put('{option}', [ProductOptionController::class, 'update']);
                    Route::delete('{option}', [ProductOptionController::class, 'destroy']);

                    // Option Values
                    Route::prefix('{option}/values')->group(function () {
                        Route::post('/', [ProductOptionValueController::class, 'store']);
                        Route::put('{value}', [ProductOptionValueController::class, 'update']);
                        Route::delete('{value}', [ProductOptionValueController::class, 'destroy']);

                        // Option Value Images
                        Route::post('{value}/image', [ProductOptionValueController::class, 'storeImage']);
                        Route::delete('{value}/image', [ProductOptionValueController::class, 'destroyImage']);
                    });
                });

                // Variants
                Route::prefix('variants')->group(function () {
                    Route::get('/', [ProductVariantController::class, 'index']);
                    Route::post('/', [ProductVariantController::class, 'store']);
                    Route::patch('{variant}', [ProductVariantController::class, 'update']);
                    Route::delete('{variant}', [ProductVariantController::class, 'destroy']);
                    Route::patch('{variant}/option-values', [ProductVariantController::class, 'updateOptionValues']);
                });

                // Addon Groups
                Route::prefix('addon-groups')->group(function () {
                    Route::get('/', [AddonGroupController::class, 'index']);
                    Route::post('/', [AddonGroupController::class, 'store']);
                    Route::patch('{group}', [AddonGroupController::class, 'update']);
                    Route::delete('{group}', [AddonGroupController::class, 'destroy']);

                    // Addon Group Options
                    Route::prefix('{group}/options')->group(function () {
                        Route::post('/', [AddonGroupOptionController::class, 'store']);
                        Route::patch('{option}', [AddonGroupOptionController::class, 'update']);
                        Route::delete('{option}', [AddonGroupOptionController::class, 'destroy']);
                    });
                });
            });
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });
});
