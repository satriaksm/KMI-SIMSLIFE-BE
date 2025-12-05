<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\CategoryController;

// Controllers lama (Jasa / Promo / Orders)
use App\Http\Controllers\JasaController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\OrderController;

// Controllers baru
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\CommunityPostController;
use App\Http\Controllers\PostCommentController;

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

        Route::get('/toko', [ProductController::class, 'publicIndexToko'])->name('toko');
        Route::get('/kuliner', [ProductController::class, 'publicIndexKuliner'])->name('kuliner');

        // ✅ Public show by slug (only published)
        Route::get('/{slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('show');



        // Get variant availability by selected option values
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('variant');
    });

    // ✅ NEW: Public Merchants
    Route::prefix('merchants')->name('merchants.')->group(function () {
        // List merchants (with pagination & filters)
        Route::get('/', [MerchantController::class, 'publicIndex'])->name('index');

        // Random merchants for homepage
        Route::get('/random', [MerchantController::class, 'publicRandom'])->name('random');

        // Show single merchant
        Route::get('/{slugOrId}', [MerchantController::class, 'publicShow'])->name('show');

        // Merchant's products (already exists)
        Route::get('/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
            ->name('products');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

});

// ✅ Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])
        ->name('images.show');
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
// PROTECTED ROUTES (AUTH + VERIFIED)
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

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD list & create
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');

            Route::post('/bulk-delete', [ProductController::class, 'bulkDelete'])->name('bulk-delete');
            Route::post('/bulk-update-status', [ProductController::class, 'bulkUpdateStatus'])->name('bulk-update-status');


            // ✅ Move export routes ABOVE dynamic {slug}
            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);

            // ✅ Slug routes with constraints to avoid matching "export"
            Route::get('{slug}', [ProductController::class, 'show'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('show');

            Route::put('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update');

            Route::patch('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update.patch');

            Route::delete('{slug}', [ProductController::class, 'destroy'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('destroy');

            // ✅ Endpoint khusus status pakai slug
            Route::patch('{slug}/status', [ProductController::class, 'updateStatus'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update-status');

            // Variant combination counter
            Route::get('{slug}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->where('slug', '^[a-z0-9-]+$');

            // Upload images by slug
            Route::post('{slug}/images', [ProductController::class, 'storeImage'])
                ->where('slug', '^[a-z0-9-]+$');
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });

    // ✅ PROTECTED Community Actions (require auth)
    Route::prefix('community')->group(function () {

        // My Posts (auth required)
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts'])
            ->name('community.posts.my');

        // Create, Update, Delete Posts (auth required)
        Route::post('/posts', [CommunityPostController::class, 'store'])
            ->name('community.posts.store');
        Route::put('/posts/{id}', [CommunityPostController::class, 'update'])
            ->name('community.posts.update');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy'])
            ->name('community.posts.destroy');

        // Comments CRUD (auth required)
        Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])
            ->name('community.comments.store');
        Route::post('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'reply'])
            ->name('community.comments.reply');
        Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])
            ->name('community.comments.destroy');
        Route::get('/my-comments', [PostCommentController::class, 'myComments'])
            ->name('community.comments.my-comments');
    });
});

// ============================================================
// PUBLIC ROUTES (No Auth Required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // Public Products
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');

        // ✅ Public show by slug (only published)
        Route::get('/{slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('show');

        // Get variant availability by selected option values
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('variant');
    });

    // ✅ NEW: Public Merchants
    Route::prefix('merchants')->name('merchants.')->group(function () {
        // List merchants (with pagination & filters)
        Route::get('/', [MerchantController::class, 'publicIndex'])->name('index');

        // Random merchants for homepage
        Route::get('/random', [MerchantController::class, 'publicRandom'])->name('random');

        // Show single merchant
        Route::get('/{slugOrId}', [MerchantController::class, 'publicShow'])->name('show');

        // Merchant's products (already exists)
        Route::get('/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
            ->name('products');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

});

// ✅ Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])
        ->name('images.show');
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
// PROTECTED ROUTES (AUTH + VERIFIED)
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

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD list & create
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');

            // ✅ Move export routes ABOVE dynamic {slug}
            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);

            // ✅ Slug routes with constraints to avoid matching "export"
            Route::get('{slug}', [ProductController::class, 'show'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('show');

            Route::put('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update');

            Route::patch('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update.patch');

            Route::delete('{slug}', [ProductController::class, 'destroy'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('destroy');

            // ✅ Endpoint khusus status pakai slug
            Route::patch('{slug}/status', [ProductController::class, 'updateStatus'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update-status');

            // Variant combination counter
            Route::get('{slug}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->where('slug', '^[a-z0-9-]+$');

            // Upload images by slug
            Route::post('{slug}/images', [ProductController::class, 'storeImage'])
                ->where('slug', '^[a-z0-9-]+$');
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });

    // ✅ PROTECTED Community Actions (require auth)
    Route::prefix('community')->group(function () {

        // My Posts (auth required)
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts'])
            ->name('community.posts.my');

        // Create, Update, Delete Posts (auth required)
        Route::post('/posts', [CommunityPostController::class, 'store'])
            ->name('community.posts.store');
        Route::put('/posts/{id}', [CommunityPostController::class, 'update'])
            ->name('community.posts.update');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy'])
            ->name('community.posts.destroy');

        // Comments CRUD (auth required)
        Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])
            ->name('community.comments.store');
        Route::post('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'reply'])
            ->name('community.comments.reply');
        Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])
            ->name('community.comments.destroy');
        Route::get('/my-comments', [PostCommentController::class, 'myComments'])
            ->name('community.comments.my-comments');
    });
});

// ============================================================
// PUBLIC ROUTES (No Auth Required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // Public Products
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');

        // ✅ Public show by slug (only published)
        Route::get('/{slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('show');

        // Get variant availability by selected option values
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('variant');
    });

    // ✅ NEW: Public Merchants
    Route::prefix('merchants')->name('merchants.')->group(function () {
        // List merchants (with pagination & filters)
        Route::get('/', [MerchantController::class, 'publicIndex'])->name('index');

        // Random merchants for homepage
        Route::get('/random', [MerchantController::class, 'publicRandom'])->name('random');

        // Show single merchant
        Route::get('/{slugOrId}', [MerchantController::class, 'publicShow'])->name('show');

        // Merchant's products (already exists)
        Route::get('/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
            ->name('products');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

});

// ✅ Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])
        ->name('images.show');
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
// PROTECTED ROUTES (AUTH + VERIFIED)
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

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD list & create
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');

            // ✅ Move export routes ABOVE dynamic {slug}
            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);

            // ✅ Slug routes with constraints to avoid matching "export"
            Route::get('{slug}', [ProductController::class, 'show'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('show');

            Route::put('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update');

            Route::patch('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update.patch');

            Route::delete('{slug}', [ProductController::class, 'destroy'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('destroy');

            // ✅ Endpoint khusus status pakai slug
            Route::patch('{slug}/status', [ProductController::class, 'updateStatus'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update-status');

            // Variant combination counter
            Route::get('{slug}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->where('slug', '^[a-z0-9-]+$');

            // Upload images by slug
            Route::post('{slug}/images', [ProductController::class, 'storeImage'])
                ->where('slug', '^[a-z0-9-]+$');
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });

    // ✅ PROTECTED Community Actions (require auth)
    Route::prefix('community')->group(function () {

        // My Posts (auth required)
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts'])
            ->name('community.posts.my');

        // Create, Update, Delete Posts (auth required)
        Route::post('/posts', [CommunityPostController::class, 'store'])
            ->name('community.posts.store');
        Route::put('/posts/{id}', [CommunityPostController::class, 'update'])
            ->name('community.posts.update');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy'])
            ->name('community.posts.destroy');

        // Comments CRUD (auth required)
        Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])
            ->name('community.comments.store');
        Route::post('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'reply'])
            ->name('community.comments.reply');
        Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])
            ->name('community.comments.destroy');
        Route::get('/my-comments', [PostCommentController::class, 'myComments'])
            ->name('community.comments.my-comments');
    });
});

// ============================================================
// PUBLIC ROUTES (No Auth Required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // Public Products
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');

        // ✅ Public show by slug (only published)
        Route::get('/{slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('show');

        // Get variant availability by selected option values
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('variant');
    });

    // ✅ NEW: Public Merchants
    Route::prefix('merchants')->name('merchants.')->group(function () {
        // List merchants (with pagination & filters)
        Route::get('/', [MerchantController::class, 'publicIndex'])->name('index');

        // Random merchants for homepage
        Route::get('/random', [MerchantController::class, 'publicRandom'])->name('random');

        // Show single merchant
        Route::get('/{slugOrId}', [MerchantController::class, 'publicShow'])->name('show');

        // Merchant's products (already exists)
        Route::get('/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
            ->name('products');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

});

// ✅ Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])
        ->name('images.show');
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
// PROTECTED ROUTES (AUTH + VERIFIED)
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

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD list & create
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');

            // ✅ Move export routes ABOVE dynamic {slug}
            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);

            // ✅ Slug routes with constraints to avoid matching "export"
            Route::get('{slug}', [ProductController::class, 'show'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('show');

            Route::put('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update');

            Route::patch('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update.patch');

            Route::delete('{slug}', [ProductController::class, 'destroy'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('destroy');

            // ✅ Endpoint khusus status pakai slug
            Route::patch('{slug}/status', [ProductController::class, 'updateStatus'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update-status');

            // Variant combination counter
            Route::get('{slug}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->where('slug', '^[a-z0-9-]+$');

            // Upload images by slug
            Route::post('{slug}/images', [ProductController::class, 'storeImage'])
                ->where('slug', '^[a-z0-9-]+$');
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });

    // ✅ PROTECTED Community Actions (require auth)
    Route::prefix('community')->group(function () {

        // My Posts (auth required)
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts'])
            ->name('community.posts.my');

        // Create, Update, Delete Posts (auth required)
        Route::post('/posts', [CommunityPostController::class, 'store'])
            ->name('community.posts.store');
        Route::put('/posts/{id}', [CommunityPostController::class, 'update'])
            ->name('community.posts.update');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy'])
            ->name('community.posts.destroy');

        // Comments CRUD (auth required)
        Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])
            ->name('community.comments.store');
        Route::post('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'reply'])
            ->name('community.comments.reply');
        Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])
            ->name('community.comments.destroy');
        Route::get('/my-comments', [PostCommentController::class, 'myComments'])
            ->name('community.comments.my-comments');
    });
});

// ============================================================
// PUBLIC ROUTES (No Auth Required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // Public Products
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');

        // ✅ Public show by slug (only published)
        Route::get('/{slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('show');

        // Get variant availability by selected option values
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])
            ->where('slug', '^[a-z0-9-]+$')
            ->name('variant');
    });

    // ✅ NEW: Public Merchants
    Route::prefix('merchants')->name('merchants.')->group(function () {
        // List merchants (with pagination & filters)
        Route::get('/', [MerchantController::class, 'publicIndex'])->name('index');

        // Random merchants for homepage
        Route::get('/random', [MerchantController::class, 'publicRandom'])->name('random');

        // Show single merchant
        Route::get('/{slugOrId}', [MerchantController::class, 'publicShow'])->name('show');

        // Merchant's products (already exists)
        Route::get('/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
            ->name('products');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

});

// ✅ Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])
        ->name('images.show');
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
// PROTECTED ROUTES (AUTH + VERIFIED)
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

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD list & create
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');

            // ✅ Move export routes ABOVE dynamic {slug}
            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);

            // ✅ Slug routes with constraints to avoid matching "export"
            Route::get('{slug}', [ProductController::class, 'show'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('show');

            Route::put('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update');

            Route::patch('{slug}', [ProductController::class, 'update'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update.patch');

            Route::delete('{slug}', [ProductController::class, 'destroy'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('destroy');

            // ✅ Endpoint khusus status pakai slug
            Route::patch('{slug}/status', [ProductController::class, 'updateStatus'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update-status');

            // Variant combination counter
            Route::get('{slug}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->where('slug', '^[a-z0-9-]+$');

            // Upload images by slug
            Route::post('{slug}/images', [ProductController::class, 'storeImage'])
                ->where('slug', '^[a-z0-9-]+$');
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });

    // ✅ PROTECTED Community Actions (require auth)
    Route::prefix('community')->group(function () {

        // My Posts (auth required)
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts'])
            ->name('community.posts.my');

        // Create, Update, Delete Posts (auth required)
        Route::post('/posts', [CommunityPostController::class, 'store'])
            ->name('community.posts.store');
        Route::put('/posts/{id}', [CommunityPostController::class, 'update'])
            ->name('community.posts.update');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy'])
            ->name('community.posts.destroy');

        // Comments CRUD (auth required)
        Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])
            ->name('community.comments.store');
        Route::post('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'reply'])
            ->name('community.comments.reply');
        Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])
            ->name('community.comments.destroy');
        Route::get('/my-comments', [PostCommentController::class, 'myComments'])
            ->name('community.comments.my-comments');
    });
});
