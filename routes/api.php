<?php

use Illuminate\Support\Facades\Route;

// Controllers lama (Jasa / Promo / Orders)
use App\Http\Controllers\CartController;
use App\Http\Controllers\JasaController;

// Controllers baru
use App\Http\Controllers\EventController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaguyubanController;
use App\Http\Controllers\PostCommentController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\CommunityPostController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEventController;
use App\Http\Controllers\Admin\AdminMerchantController;
use App\Http\Controllers\Admin\ContentReportController;
use App\Http\Controllers\ProductOptionValueImageController;

// ============================================================
// HEALTH CHECK
// ============================================================
Route::get('/', fn() => response()->json(['status' => 'API is running']));


// ============================================================
// PUBLIC ROUTES (No Auth Required)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    Route::get('search', [SearchController::class, 'searchProducts'])->name('search');
    Route::get('search-merchants', [SearchController::class, 'searchMerchants'])->name('search.merchants');


    // Public Products
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        // Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');

        // ✅ Public show by slug (only published)
        Route::get('/{slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[A-Za-z0-9-]+$')
            ->name('show');


    });

    // Public Merchants
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
        // Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        // Route::get('/search', [CategoryController::class, 'searchCategories']);
    });
});
Route::get('segmentations', [SegmentationController::class, 'index'])->name('segmentations.index');

// Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});


// ============================================================
// PUBLIC API DARI SISTEM LAMA (JASA / PROMO / ORDER)
// ============================================================
Route::get('images/{image}', [ImageController::class, 'show'])
    ->name('images.show');
Route::get('images/product-option-value/{optionValue}', [ProductOptionValueImageController::class, 'show'])
    ->name('images.product-option-value.show');

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
    // Public auth endpoints with rate limiting for security
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:5,60')
        ->name('register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:3,60')
        ->name('forgot-password');

    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,60')
        ->name('reset-password');

    // Email verification
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('api.verification.send');
    });
});

Route::middleware(['auth:sanctum'])->get('/me', [AuthController::class, 'me'])->name('me');

// ============================================================
// PROTECTED ROUTES (AUTH + VERIFIED)
// ============================================================
// Wrap protected routes with 'web' so session/cookie middlewares are available,
// then apply 'auth:sanctum' and other guards.
Route::middleware(['auth:sanctum', 'verified'])->group(function () {

    // Locations
    Route::prefix('locations')->controller(LocationController::class)->group(function () {
        Route::get('provinces', 'provinces');
        Route::get('cities/{provinceId}', 'cities');
        Route::get('districts/{cityId}', 'districts');
        Route::get('villages/{districtId}', 'villages');
    });

    Route::prefix('cart')->group(function () {

        Route::get('/', [CartController::class, 'index']);
        Route::get('/count', [CartController::class, 'count']);
        Route::post('/items', [CartController::class, 'addToCart']);
        Route::patch('/items/{cartItem}', [CartController::class, 'updateQuantity']);
        Route::patch('/items/{cartItem}/variant', [CartController::class, 'updateVariant']);
        Route::delete('/items/{cartItem}', [CartController::class, 'removeItem']);
        Route::delete('/{cart}', [CartController::class, 'clearCart']);
    });


    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    Route::get('checkout/{merchant}/vouchers', [VoucherController::class, 'customerVouchersByMerchant']);

    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        Route::get(
            'merchants/{merchant}/dashboard',
            [DashboardController::class, 'merchantDashboard']
        );

        Route::prefix('merchant')->group(function () {
            Route::get('{merchant}/vouchers', [VoucherController::class, 'merchantIndex']);
            Route::get('{merchant}/vouchers/{voucher}', [VoucherController::class, 'merchantShow']);
            Route::post('{merchant}/vouchers', [VoucherController::class, 'merchantStore']);
            Route::put('{merchant}/vouchers/{voucher}', [VoucherController::class, 'merchantUpdate']);
            Route::delete('{merchant}/vouchers/{voucher}', [VoucherController::class, 'merchantDestroy']);

            Route::post('{merchant}/vouchers/bulk-delete', [VoucherController::class, 'bulkDelete'])->name('merchant.bulk-delete');
            Route::post('{merchant}/vouchers/bulk-update-status', [VoucherController::class, 'bulkUpdateStatus'])->name('merchant.bulk-update-status');


            Route::patch('{merchant}/vouchers/{voucher}/status', [VoucherController::class, 'updateStatus'])
                ->name('merchant.update-status');
        });


        // PRODUCT CRUD & NESTED
        Route::prefix('products')->name('products.')->group(function () {
            // Product CRUD list & create
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');

            Route::post('/bulk-delete', [ProductController::class, 'bulkDelete'])->name('bulk-delete');
            Route::post('/bulk-update-status', [ProductController::class, 'bulkUpdateStatus'])->name('bulk-update-status');

            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);
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

            Route::patch('{slug}/status', [ProductController::class, 'updateStatus'])
                ->where('slug', '^[a-z0-9-]+$')
                ->name('update-status');

            // Variant combination counter
            Route::get('{slug}/combinations-count', [ProductController::class, 'getCombinationCount'])
                ->where('slug', '^[a-z0-9-]+$');
        });
    });

    // ADMIN ONLY: merchant approval
    Route::middleware('role:admin')->prefix('merchants')->group(function () {
        Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
        Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
    });

    // PROTECTED Community Actions (require auth)
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

// Public Community Posts (tanpa auth)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});

// ============================================================
// PUBLIC API DARI SISTEM LAMA (JASA / PROMO / ORDER)
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
// ADMIN ROUTES (Protected)
// ============================================================
Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {

    // ===== DASHBOARD STATISTICS =====
    Route::get('/dashboard/statistics', [AdminDashboardController::class, 'statistics'])->name('dashboard.statistics');
    Route::get('/dashboard/orders-revenue', [AdminDashboardController::class, 'ordersRevenue']);

    // ===== DASHBOARD USER MANAGEMENT (NEW) =====
    Route::get('/dashboard', [AdminUserController::class, 'dashboard'])->name('dashboard');

    // ===== USER MANAGEMENT =====
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('index');
        Route::post('/', [AdminUserController::class, 'store'])->name('store');
        Route::get('/roles', [AdminUserController::class, 'getRoles'])->name('roles');
        Route::get('/{id}/login-trend', [AdminUserController::class, 'loginTrend'])->name('login-trend');
        Route::get('/overview-stats', [AdminUserController::class, 'overviewStats'])->name('overview-stats');
        Route::get('/{id}', [AdminUserController::class, 'show'])->name('show');
        Route::put('/{id}', [AdminUserController::class, 'update'])->name('update');
        Route::delete('/{id}', [AdminUserController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-status', [AdminUserController::class, 'bulkUpdateStatus'])->name('bulk-status');

        // ===== USER ACTIONS (NEW) =====
        Route::post('/{id}/warn', [AdminUserController::class, 'warn'])->name('warn');
        Route::post('/{id}/suspend', [AdminUserController::class, 'suspend'])->name('suspend');
        Route::post('/{id}/unsuspend', [AdminUserController::class, 'unsuspend'])->name('unsuspend');
        Route::patch('/{id}/status', [AdminUserController::class, 'changeStatus'])->name('change-status');
        Route::post('/{id}/notify', [AdminUserController::class, 'notify'])->name('notify');

        // Merchant Approval (nested under users)
        Route::prefix('merchants')->name('merchants.')->group(function () {
            Route::get('/{merchantId}', [AdminUserController::class, 'showMerchant'])->name('show');
            Route::patch('/{merchantId}/approve', [AdminUserController::class, 'approveMerchant'])->name('approve');
            Route::patch('/{merchantId}/reject', [AdminUserController::class, 'rejectMerchant'])->name('reject');
        });
    });

    // ===== MERCHANT MANAGEMENT =====
    Route::prefix('merchants')->name('merchants.')->group(function () {
        Route::get('/', [AdminMerchantController::class, 'index'])->name('index');
        Route::post('/', [AdminMerchantController::class, 'store'])->name('store');
        Route::get('/{id}', [AdminMerchantController::class, 'show'])->name('show');
        Route::patch('/{merchant}/approve', [AdminMerchantController::class, 'approve'])->name('approve');
        Route::patch('/{merchant}/reject', [AdminMerchantController::class, 'reject'])->name('reject');
        Route::patch('/{id}/toggle-status', [AdminMerchantController::class, 'toggleStatus'])->name('toggle-status');
        Route::get('/{id}/statistics', [AdminMerchantController::class, 'statistics'])->name('statistics');
    });

    // ===== PAGUYUBAN MANAGEMENT =====
    Route::prefix('paguyubans')->name('paguyubans.')->group(function () {
        Route::get('/', [PaguyubanController::class, 'index'])->name('index');
        Route::post('/', [PaguyubanController::class, 'store'])->name('store');
        Route::get('/{id}', [PaguyubanController::class, 'show'])->name('show');
        Route::put('/{id}', [PaguyubanController::class, 'update'])->name('update');
        Route::delete('/{id}', [PaguyubanController::class, 'destroy'])->name('destroy');
        Route::patch('/{id}/toggle-status', [PaguyubanController::class, 'toggleStatus'])->name('toggle-status');
    });

    // ===== CATEGORY MANAGEMENT =====
    Route::prefix('categories')->name('categories.')->group(function () {
        Route::get('/', [CategoryController::class, 'adminIndex'])->name('index');
        Route::post('/', [CategoryController::class, 'store'])->name('store');
        Route::get('/{id}', [CategoryController::class, 'show'])->name('show');
        Route::put('/{id}', [CategoryController::class, 'update'])->name('update');
        Route::delete('/{id}', [CategoryController::class, 'destroy'])->name('destroy');
    });

    // ===== EVENT MANAGEMENT =====
    Route::prefix('events')->name('events.')->group(function () {
        Route::get('/', [AdminEventController::class, 'index'])->name('index');
        Route::post('/', [AdminEventController::class, 'store'])->name('store');
        Route::get('/{id}', [AdminEventController::class, 'show'])->name('show');
        Route::put('/{id}', [AdminEventController::class, 'update'])->name('update');
        Route::delete('/{id}', [AdminEventController::class, 'destroy'])->name('destroy');
        Route::post('/{event}/invite-merchants', [AdminEventController::class, 'inviteMerchants'])->name('invite-merchants');
    });

    // ===== VOUCHER MANAGEMENT =====
    Route::prefix('vouchers')->name('vouchers.')->group(function () {
        Route::get('/', [VoucherController::class, 'adminIndex'])->name('index');
        Route::get('/{id}', [VoucherController::class, 'adminShow'])->name('show');
        Route::delete('/{id}', [VoucherController::class, 'destroy'])->name('destroy');
    });

    // ===== CONTENT REPORTS (MOVED FROM OUTSIDE) =====
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ContentReportController::class, 'index'])->name('index');
        Route::get('/{id}', [ContentReportController::class, 'show'])->name('show');
        Route::put('/{id}', [ContentReportController::class, 'update'])->name('update');
        Route::patch('/{id}/review', [ContentReportController::class, 'review'])->name('review');
        Route::delete('/{id}', [ContentReportController::class, 'destroy'])->name('destroy');
    });

    // Report Reasons
    Route::get('/report-reasons', [ContentReportController::class, 'reasons'])->name('report-reasons');

    // ===== COMMUNITY MODERATION =====
    Route::prefix('community')->name('community.')->group(function () {
        Route::get('/posts', [CommunityPostController::class, 'adminIndex'])->name('posts.index');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'adminDestroy'])->name('posts.destroy');
        Route::get('/comments', [PostCommentController::class, 'adminIndex'])->name('comments.index');
        Route::delete('/comments/{id}', [PostCommentController::class, 'adminDestroy'])->name('comments.destroy');
    });

    // ===== PRODUCT MODERATION =====
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'adminIndex'])->name('index');
        Route::delete('/{id}', [ProductController::class, 'adminDestroy'])->name('destroy');
    });
});
