<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CartController;
use App\Http\Controllers\JasaController;
use App\Http\Controllers\JasaCategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\JasaCategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaguyubanController;
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
use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\Admin\AdminVoucherController;
use App\Http\Controllers\Product\ProductOptionValueImageController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Product\ProductOptionValueImageController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RatingController;
use App\Models\Conversation;

// ============================================================
// HEALTH CHECK
// ============================================================
Route::get('/', fn() => response()->json(['status' => 'API is running']));


// ============================================================
// PUBLIC ROUTES (NO AUTH REQUIRED)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // -------- PRODUCTS (Kuliner/Toko Catalog) --------
    Route::get('search', [SearchController::class, 'searchProducts'])->name('search');
    Route::get('search-merchants', [SearchController::class, 'searchMerchants'])->name('search.merchants');

    // Public jasa listing (only published/active)
    // Route::get('/jasas', [JasaController::class, 'publicIndex']);
    
    // Jasa Ratings (public - view only) - MUST BE BEFORE ID/SLUG routes
    Route::get('/jasas/{jasaId}/ratings/summary', [RatingController::class, 'jasaSummary'])->name('jasas.ratings.summary');
    Route::get('/jasas/{jasaId}/ratings', [RatingController::class, 'indexForJasa'])->name('jasas.ratings');
    
    // Jasa by ID (numeric only - must come FIRST so it matches before slug)
    Route::get('/jasas/{id}', [JasaController::class, 'publicShow'])
        ->whereNumber('id')
        ->name('jasas.show');
    
    // Jasa by slug (must contain at least one letter, come AFTER numeric check)
    Route::get('/jasas/{slug}', [JasaController::class, 'publicShowBySlug'])
        ->where('slug', '^(?!\d+$).+')
        ->name('jasas.show.slug');

    Route::prefix('products')->name('products.')->group(function () {

        Route::get('/{product:slug}', [ProductController::class, 'publicShow'])
            ->where('slug', '^[A-Za-z0-9-]+$')
            ->name('show');

        // Product Ratings (public - view only)
        Route::get('/{productId}/ratings', [RatingController::class, 'indexForProduct'])->name('ratings');
        Route::get('/{productId}/ratings/summary', [RatingController::class, 'productSummary'])->name('ratings.summary');
    });

    // Public Merchants
    Route::prefix('merchants')->name('merchants.')->group(function () {

        Route::get('/map', [MerchantController::class, 'mapIndex'])->name('map.index');

        // Show single merchant
        Route::get('/{merchantSlug}', [MerchantController::class, 'publicShow'])->name('show');

        // Merchant's products (already exists)
        Route::get('/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
            ->name('products');

        // Merchant's jasa (published/active only)
        Route::get('/{merchantSlug}/jasas', [JasaController::class, 'publicByMerchant'])
            ->where('merchantSlug', '^[A-Za-z0-9-]+$')
            ->name('jasas');

        // Merchant's ratings/reviews (public endpoint)
        Route::get('/{merchantSlug}/ratings/summary', [RatingController::class, 'merchantSummaryBySlug'])
            ->where('merchantSlug', '^[A-Za-z0-9-]+$')
            ->name('ratings.summary');

        // Merchant's individual ratings (public endpoint - for merchant reviews page)
        Route::get('/{merchantSlug}/ratings', [RatingController::class, 'indexForMerchantBySlug'])
            ->where('merchantSlug', '^[A-Za-z0-9-]+$')
            ->name('ratings');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
    });

    Route::get('segmentations', [SegmentationController::class, 'index'])->name('segmentations.index');

    // Locations
    Route::prefix('locations')->controller(LocationController::class)->group(function () {
        Route::get('provinces', 'provinces');
        Route::get('cities/{provinceId}', 'cities');
        Route::get('districts/{cityId}', 'districts');
        Route::get('villages/{districtId}', 'villages');
    });

    //  events endpoint (published only, for homepage banner)
    //  events endpoint (published only, for homepage banner)
    Route::get('events', [EventController::class, 'publicIndex'])->name('events.index');

    //  Homepage specific endpoints
    Route::prefix('home')->name('home.')->group(function () {
        Route::get('recommended-merchants', [\App\Http\Controllers\HomeController::class, 'recommendedMerchants'])
            ->name('recommended-merchants');
        Route::get('map-carousel-merchants', [\App\Http\Controllers\HomeController::class, 'mapCarouselMerchants'])
            ->name('map-carousel-merchants');
        Route::get('statistics', [\App\Http\Controllers\HomeController::class, 'statistics'])
            ->name('statistics');
    });

    //  Homepage specific endpoints
    Route::prefix('home')->name('home.')->group(function () {
        Route::get('recommended-merchants', [\App\Http\Controllers\HomeController::class, 'recommendedMerchants'])
            ->name('recommended-merchants');
        Route::get('map-carousel-merchants', [\App\Http\Controllers\HomeController::class, 'mapCarouselMerchants'])
            ->name('map-carousel-merchants');
        Route::get('statistics', [\App\Http\Controllers\HomeController::class, 'statistics'])
            ->name('statistics');
    });
});


// Public Community Posts (harusnya masuk ke prefix public)
Route::prefix('community')->name('community.')->group(function () {
    Route::get('/posts', [CommunityPostController::class, 'index'])->name('posts.index');
    Route::get('/posts/popular', [CommunityPostController::class, 'popular'])->name('posts.popular');
    Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->name('comments.index');
    Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])->name('comments.replies');
});

// ============================================================
// API SERVED IMAGES
// ============================================================
Route::get('images/{image}', [ImageController::class, 'show'])
    ->name('images.show');
// Cart item snapshot images (served via API - avoids direct /storage access)
Route::get('cart-snapshots/{cartItem}', [ImageController::class, 'cartSnapshot'])
    ->name('cart-snapshots.show');
// Route::get('images/product-option-value/{optionValue}', [ProductOptionValueImageController::class, 'show'])
//     ->name('images.product-option-value.show');

Route::get('profile-pictures/{user}', [UserController::class, 'profilePictureShow'])
Route::get('profile-pictures/{user}', [UserController::class, 'profilePictureShow'])
    ->name('profile-pictures.show');

// Backward/alternate naming (underscore) for clients that expect it
Route::get('profile_pictures/{user}', [UserController::class, 'profilePictureShow'])
Route::get('profile_pictures/{user}', [UserController::class, 'profilePictureShow'])
    ->name('profile_pictures.show');

Route::get('merchant-profile-pictures/{merchant}', [MerchantController::class, 'merchantProfilePictureShow'])
    ->name('merchant_profile_pictures.show');
Route::get('merchant-banner/{merchant}', [MerchantController::class, 'merchantBannerShow'])
    ->name('merchant_banner.show');

// ============================================================
// AUTH ROUTES
// ============================================================
Route::prefix('auth')->group(function () {
    // Public auth endpoints with rate limiting for security
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('forgot-password');

    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('reset-password');

    // Email verification
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->withoutMiddleware([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ])
        ->middleware(['throttle:5,1'])
        ->name('api.verification.verify');

    Route::middleware(['auth'])->group(function () {
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:5,1')
            ->name('api.verification.send');
    });
});



// ============================================================
// PROTECTED ROUTES (AUTH + VERIFIED)
// ============================================================
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/me', [AuthController::class, 'me'])->name('me');

    Route::prefix('profile')->controller(UserController::class)->group(function () {
        Route::get('/', 'show')->name('profile.show');
        Route::post('/update', 'update')->name('profile.update');
        Route::post('/change-password', 'changePassword')->name('profile.change-password');
        Route::get('/address', 'addressShow')->name('profile.address.show');
        Route::post('/address', 'addressUpsert')->name('profile.address.upsert');
        Route::delete('/', 'destroy')->name('profile.destroy');
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
        Route::delete('/', 'destroy')->name('profile.destroy');
    });

    // ===== RATINGS (for all authenticated users) =====
    Route::prefix('ratings')->name('ratings.')->group(function () {
        Route::post('/', [RatingController::class, 'store'])->name('store');
        Route::get('/{ratingId}', [RatingController::class, 'show'])->name('show');
        Route::put('/{ratingId}', [RatingController::class, 'update'])->name('update');
        Route::delete('/{ratingId}', [RatingController::class, 'destroy'])->name('destroy');
    });

    // ===== CHATS/MESSAGING (for all authenticated users) =====
    Route::prefix('chats')->name('chats.')->group(function () {
        Route::post('/start', [ChatController::class, 'start'])->name('start');
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('/{conversation}', [ChatController::class, 'show'])->name('show');
        Route::post('/{conversation}/messages', [ChatController::class, 'sendMessage'])->name('messages.store');
        Route::post('/{conversation}/buyer-messages', [ChatController::class, 'sendBuyerMessage'])->name('messages.buyer.store');
        Route::post('/{conversation}/offer', [ChatController::class, 'makeOffer'])->name('offer.store');
        Route::put('/{conversation}/offer/accept', [ChatController::class, 'acceptOffer'])->name('offer.accept');
        Route::put('/{conversation}/status', [ChatController::class, 'updateStatus'])->name('status.update');
        Route::delete('/{conversation}', [ChatController::class, 'destroy'])->name('destroy');
    });

    // CUSTOMER ONLY: Register Merchant
    Route::middleware('role:customer')->group(function () {

        Route::prefix('cart')->group(function () {
            Route::get('/', [CartController::class, 'index']);
            Route::get('/count', [CartController::class, 'count']);
            Route::post('/items', [CartController::class, 'addToCart']);
            Route::patch('/items/{cartItem}', [CartController::class, 'updateQuantity']);
            Route::patch('/items/{cartItem}/variant', [CartController::class, 'updateVariant']);
            Route::delete('/items/{cartItem}', [CartController::class, 'removeItem']);
            Route::delete('/{cart}', [CartController::class, 'clearCart']);
        });

        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');

        Route::get('checkout/{merchant:slug}/vouchers', [VoucherController::class, 'customerVouchersByMerchant']);

    });


    // UMKM OWNER ONLY
    Route::middleware('role:umkm-owner')->group(function () {

        // ---------- JASA ----------
        Route::prefix('jasa')->group(function () {
            Route::get('/', [JasaController::class, 'index']);
            Route::get('/{id}', [JasaController::class, 'show']);
            Route::post('/', [JasaController::class, 'store']);
            Route::put('/{id}', [JasaController::class, 'update']);
            Route::delete('/{id}', [JasaController::class, 'destroy']);
        });

        // Create jasa for a merchant (preferred: slug-based)
        Route::post('/merchants/{merchantSlug}/jasas', [JasaController::class, 'storeForMerchantBySlug'])
            ->where('merchantSlug', '^[A-Za-z0-9-]+$');

        // Backward compatibility: numeric merchantId endpoint
        // Route::post('/merchants/{merchantId}/jasas', [JasaController::class, 'storeForMerchant'])
        //     ->whereNumber('merchantId');

        Route::prefix('merchant/{merchant:slug}')->group(function () {
        Route::prefix('merchant/{merchant:slug}')->group(function () {

            Route::get(
                'dashboard',
                [DashboardController::class, 'merchantDashboard']
            );

            Route::get('profile', [MerchantController::class, 'showMyMerchant'])->name('show.profile');
            Route::post('update', [MerchantController::class, 'updateMyMerchant'])->name('edit.profile');
            Route::delete('', [MerchantController::class, 'destroyMyMerchant'])->name('merchant.destroy');

            // Route::prefix('events')->name('events.')->group(function () {
            //     Route::get('/', [EventController::class, 'indexByMerchant'])->name('index');
            //     Route::get('/{id}', [EventController::class, 'show'])->name('show');

            //     Route::post('/{id}', [EventController::class, 'approvalByMerchant'])->name('approval');
            // });

            Route::prefix('products')->name('merchant.products.')->group(function () {
                Route::get('', [ProductController::class, 'index'])->name('index');
                Route::post('', [ProductController::class, 'store'])->name('store');

                Route::post('bulk-delete', [ProductController::class, 'bulkDelete'])->name('bulk-delete');
                Route::post('bulk-update-status', [ProductController::class, 'bulkUpdateStatus'])->name('bulk-update-status');
                Route::post('bulk-delete', [ProductController::class, 'bulkDelete'])->name('bulk-delete');
                Route::post('bulk-update-status', [ProductController::class, 'bulkUpdateStatus'])->name('bulk-update-status');

                Route::get('export/excel', [ProductController::class, 'exportExcel']);
                Route::get('export/pdf', [ProductController::class, 'exportPdf']);

                Route::prefix('{product:slug}')->group(function () {
                    Route::get('', [ProductController::class, 'show'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('show');
                Route::get('export/excel', [ProductController::class, 'exportExcel']);
                Route::get('export/pdf', [ProductController::class, 'exportPdf']);

                Route::prefix('{product:slug}')->group(function () {
                    Route::get('', [ProductController::class, 'show'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('show');

                    Route::put('', [ProductController::class, 'update'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('update');

                    Route::patch('', [ProductController::class, 'update'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('update.patch');
                    Route::patch('', [ProductController::class, 'update'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('update.patch');

                    Route::delete('', [ProductController::class, 'destroy'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('destroy');
                    Route::delete('', [ProductController::class, 'destroy'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('destroy');

                    Route::patch('/status', [ProductController::class, 'updateStatus'])
                        ->where('product', '^[a-z0-9-]+$')
                        ->name('update-status');
                });
            });

            Route::prefix('vouchers')->name('merchant.vouchers.')->group(function () {
                Route::get('', [VoucherController::class, 'merchantIndex']);
                Route::post('', [VoucherController::class, 'merchantStore']);
                Route::get('{voucher}', [VoucherController::class, 'merchantShow']);
                Route::put('{voucher}', [VoucherController::class, 'merchantUpdate']);
                Route::delete('{voucher}', [VoucherController::class, 'merchantDestroy']);
                Route::patch('{voucher}/status', [VoucherController::class, 'updateStatus'])
                    ->name('merchant.update-status');

                Route::post('bulk-delete', [VoucherController::class, 'bulkDelete'])->name('merchant.bulk-delete');
                Route::post('bulk-update-status', [VoucherController::class, 'bulkUpdateStatus'])->name('merchant.bulk-update-status');


            });

        });
    });

    // ============================================================
    // ADMIN ROUTES (Protected)
    // ============================================================
    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {

        // ===== DASHBOARD STATISTICS =====
        Route::get('/dashboard/statistics', [AdminDashboardController::class, 'statistics'])->name('dashboard.statistics');
        Route::get('/dashboard/orders-revenue', [AdminDashboardController::class, 'ordersRevenue']);
        Route::get('/dashboard/export-pdf', [AdminDashboardController::class, 'exportPdf']); 

        // ===== DASHBOARD USER MANAGEMENT =====
        Route::get('/dashboard', [AdminUserController::class, 'dashboard'])->name('dashboard');

        // Manage Admins (Super Admin only - validated in controller)
        Route::prefix('manage-admins')->name('manage-admins.')->group(function () {
            Route::get('/', [AdminManagementController::class, 'index'])->name('index');
            Route::post('/', [AdminManagementController::class, 'store'])->name('store');
            Route::patch('/{id}/toggle-status', [AdminManagementController::class, 'toggleStatus'])->name('toggle-status');
            Route::delete('/{id}', [AdminManagementController::class, 'destroy'])->name('destroy');
            Route::get('/{id}/activity-logs', [AdminManagementController::class, 'activityLogs'])->name('activity-logs');
            Route::get('/export-pdf', [AdminManagementController::class, 'exportPdf'])->name('export-pdf');
            Route::get('/{id}/export-pdf', [AdminManagementController::class, 'exportAdminDetailPdf'])->name('exportAdminDetailPdf');
        });

        // ===== USER MANAGEMENT =====
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])->name('index');
            Route::post('/', [AdminUserController::class, 'store'])->name('store');
            Route::get('/roles', [AdminUserController::class, 'getRoles'])->name('roles');
            Route::get('/{id}/login-trend', [AdminUserController::class, 'loginTrend'])->name('login-trend');
            Route::get('/overview-stats', [AdminUserController::class, 'overviewStats'])->name('overview-stats');
            Route::get('/{id}/export-pdf', [AdminUserController::class, 'exportUserDetailPdf'])->name('exportUserDetailPdf');
            Route::get('/export-pdf', [AdminUserController::class, 'exportPdf'])->name('export-pdf');

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
        });

        // ===== MERCHANT MANAGEMENT =====
        Route::prefix('merchants')->name('merchants.')->group(function () {
            Route::get('/', [AdminMerchantController::class, 'index'])->name('index');
            Route::post('/', [AdminMerchantController::class, 'store'])->name('store');
            Route::get('/{id}/export-pdf', [AdminMerchantController::class, 'exportMerchantDetailPdf'])->name('exportMerchantDetailPdf');
            Route::get('/export-pdf', [AdminMerchantController::class, 'exportPdf'])->name('export-pdf');
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
            Route::get('/export-pdf', [AdminEventController::class, 'exportPdf'])->name('export-pdf');

            //  Manual trigger auto-archive
            Route::post('/auto-archive', [AdminEventController::class, 'triggerAutoArchive'])->name('auto-archive');

            Route::get('/{id}', [AdminEventController::class, 'show'])->name('show');
            Route::put('/{id}', [AdminEventController::class, 'update'])->name('update');
            Route::delete('/{id}', [AdminEventController::class, 'destroy'])->name('destroy');
            Route::post('/{event}/invite-merchants', [AdminEventController::class, 'inviteMerchants'])->name('invite-merchants');
            Route::post('/{event}/vouchers/attach', [AdminEventController::class, 'attachVoucher']);
            Route::delete('/{event}/vouchers/{voucher}', [AdminEventController::class, 'detachVoucher']);
            Route::get('/vouchers/available', [AdminEventController::class, 'availableVouchers']);
            Route::delete('/{event}/merchants/{merchant}', [AdminEventController::class, 'removeMerchant']);
            Route::post('/{event}/merchants/{merchant}/restore', [AdminEventController::class, 'restoreMerchant']);
            Route::get('/{event}/merchants/removed', [AdminEventController::class, 'removedMerchants']);
        });

        // ===== VOUCHER MANAGEMENT =====
        Route::prefix('vouchers')->name('vouchers.')->group(function () {
            Route::get('/', [AdminVoucherController::class, 'index']);
            Route::post('/', [AdminVoucherController::class, 'store']);
            Route::get('/export-pdf', [AdminVoucherController::class, 'exportPdf'])->name('export-pdf'); // ✅ NEW
            Route::get('/{id}', [AdminVoucherController::class, 'show']);
            Route::post('/{voucher}/assign-merchants', [AdminVoucherController::class, 'assignMerchants']);
            Route::post('/{id}/activate', [AdminVoucherController::class, 'activate'])->name('activate');
            Route::post('/{id}/deactivate', [AdminVoucherController::class, 'deactivate'])->name('deactivate');
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
});

// Event banner streaming route (should already exist)
Route::get('event-banners/{event}', [AdminEventController::class, 'showBanner'])
    ->name('event-banners.show');

Route::get('event_banners/{event}', [AdminEventController::class, 'showBanner'])
    ->name('event_banners.show');

Route::get('/merchant-logo/{merchant}', [AdminMerchantController::class, 'showLogo'])
    ->name('merchant.logo');

// User profile picture streaming route
Route::get('/user-profile/{user}', [AdminUserController::class, 'showProfilePicture'])
    ->name('user.profile');

// Community post image streaming route
Route::get('community-images/{image}', [CommunityPostController::class, 'showImage'])
    ->name('community-images.show');

// Backward compatibility (underscore naming)
Route::get('community_images/{image}', [CommunityPostController::class, 'showImage'])
    ->name('community_images.show');
