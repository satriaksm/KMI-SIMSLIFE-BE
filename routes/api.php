<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ImageController;
use App\Http\Controllers\CategoryController;

// Legacy (Jasa / Promo / Orders)
use App\Http\Controllers\JasaController;
use App\Http\Controllers\JasaCategoryController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\OrderController;
use App\Models\Jasa;

// ✅ NEW: Packages (Jasa paket)
use App\Http\Controllers\PackageController;

// New
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\CommunityPostController;
use App\Http\Controllers\PostCommentController;
use App\Http\Controllers\ChatController;

// ============================================================
// CSRF COOKIE ENDPOINT (REQUIRED FOR SPA TOKEN-BASED AUTH)
// ============================================================
Route::get('/sanctum/csrf-cookie', function () {
    return response()->json(['message' => 'CSRF cookie set']);
})->middleware(['web'])->name('csrf.cookie');

// ============================================================
// HEALTH CHECK
// ============================================================
Route::get('/', fn () => response()->json(['status' => 'API is running']));

// Compatibility: define a lightweight `login` route for API middleware
// Some auth middleware calls `route('login')` when redirecting unauthenticated
// requests; if that route is missing in API context it can throw a
// RouteNotFoundException and produce a 500. Return a JSON 401 to keep API
// clients happy without touching middleware logic.
Route::get('login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

// ============================================================
// PUBLIC ROUTES (NO AUTH REQUIRED)
// ============================================================
Route::prefix('public')->name('public.')->group(function () {

    // -------- PRODUCTS (Kuliner/Toko Catalog) --------
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'publicIndex'])->name('index');
        Route::get('/featured', [ProductController::class, 'publicFeatured'])->name('featured');
        Route::get('/{slug}', [ProductController::class, 'publicShow'])->name('show');
        Route::post('/{slug}/variant', [ProductController::class, 'publicGetVariant'])->name('variant');
    });

    // -------- CATEGORIES --------
    Route::prefix('categories')->group(function () {
        Route::get('/level-1', [CategoryController::class, 'getLevel1Categories']);
        Route::get('/{parentId}/sub-categories', [CategoryController::class, 'getSubCategories']);
        Route::get('/tree', [CategoryController::class, 'getCategoriesTree']);
        Route::get('/search', [CategoryController::class, 'searchCategories']);
    });

    // -------- MERCHANT CATALOG --------
    Route::get('merchants/{merchantSlug}/products', [ProductController::class, 'publicByMerchant'])
        ->name('merchants.products');

    // -------- JASA (Customer Catalog) --------
    Route::get('jasas', [JasaController::class, 'index']);        // list jasa + packages
    Route::get('jasa', [JasaController::class, 'index']);         // ✅ LEGACY: singular alias for frontend
    Route::get('jasas/{id}', [JasaController::class, 'show']);    // detail jasa + packages
    Route::get('jasa/{id}', [JasaController::class, 'show']);     // ✅ LEGACY: singular alias for frontend
    
    // -------- JASA CATEGORIES & SUBCATEGORIES --------
    Route::get('jasa-categories', [JasaCategoryController::class, 'index']);
    Route::get('jasa-categories/{id}', [JasaCategoryController::class, 'show']);
    Route::get('jasa-categories/{id}/subcategories', [JasaCategoryController::class, 'getSubcategories']);
    
    // DEBUG: Show all jasas without filtering
    Route::get('debug/all-jasas', function () {
        return response()->json(
            Jasa::with('merchant:id,status,segmentation_id')->get()
        );
    });

    // DEBUG: Create a test jasa (non-production)
    Route::post('debug/create-jasa', function (\Illuminate\Http\Request $request) {
        if (app()->environment('production')) {
            return response()->json(['message' => 'Disabled in production'], 403);
        }

        $merchant = \App\Models\Merchant::query()->first();
        if (!$merchant) {
            return response()->json(['message' => 'No merchant found'], 400);
        }

        $jasa = new Jasa();
        $jasa->merchant_id = $merchant->id;
        $jasa->name = $request->input('name', 'Test Jasa');
        $jasa->description = $request->input('description', 'Deskripsi test jasa');
        $jasa->price = $request->input('price', 50000);
        $jasa->is_active = true;
        if ($request->has('min_purchase')) { $jasa->min_purchase = $request->input('min_purchase'); }
        $jasa->save();

        return response()->json($jasa->load('merchant'));
    });
});

// Public image streaming (needs web middleware)
Route::middleware('web')->group(function () {
    Route::get('images/{image}', [ImageController::class, 'show'])->name('images.show');
});

// ============================================================
// LEGACY PUBLIC ENDPOINTS (FE lama) - redirects to /public/*
// ============================================================
// Keep legacy FE paths working by redirecting to the new
// public-prefixed endpoints. These are simple HTTP redirects
// and avoid duplicating controller logic.
Route::redirect('jasa', 'public/jasas');
Route::redirect('jasa/{id}', 'public/jasas/{id}');

// Promo public read
Route::prefix('promos')->group(function () {
    Route::get('/', [PromoController::class, 'index']);
    Route::get('/{id}', [PromoController::class, 'show'])->name('promos.show');
});

// ============================================================
// AUTH ROUTES
// ============================================================
Route::prefix('auth')->group(function () {

    // ✅ SPA Login/Logout (no CSRF needed for API)
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('auth.logout');

    // Public auth endpoints
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('reset-password');

    // Email verification
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');

    // Protected auth endpoints
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/change-password', [PasswordResetController::class, 'change'])->name('password.change');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('api.verification.send');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });
});

// ============================================================
// PROTECTED ROUTES (AUTH + VERIFIED)
// ============================================================
// ❌ NOTE: OLD approach with 'web' middleware causes CSRF issues for API
// ✅ Use 'api' middleware for proper API endpoints
Route::middleware(['api', 'auth:sanctum'])->group(function () {

    // -------- LOCATIONS --------
    Route::prefix('locations')->controller(LocationController::class)->group(function () {
        Route::get('provinces', 'provinces');
        Route::get('cities/{provinceId}', 'cities');
        Route::get('districts/{cityId}', 'districts');
        Route::get('villages/{districtId}', 'villages');
    });

    Route::get('segmentations', [SegmentationController::class, 'index'])->name('segmentations.index');

    // -------- CUSTOMER ONLY: Register Merchant --------
    Route::middleware('role:customer')->group(function () {
        Route::post('/merchant-register', [MerchantController::class, 'register'])->name('merchant.register');
    });

    // ============================================================
    // CUSTOMER: ORDER JASA (login)
    // ============================================================
    Route::prefix('orders')->group(function () {
        Route::post('/', [OrderController::class, 'store']);          // create order (customer)
        Route::get('/mine', [OrderController::class, 'myOrders']);    // list order milik sendiri
        Route::get('/{id}', [OrderController::class, 'myOrderShow']); // detail order milik sendiri
    });

    // ============================================================
    // CHAT (Buyer & Merchant)
    // ============================================================
    Route::prefix('chats')->group(function () {
        Route::get('/', [ChatController::class, 'index']);
        Route::post('/start', [ChatController::class, 'start']);
        Route::get('/{id}', [ChatController::class, 'show']);
        Route::post('/{id}/messages', [ChatController::class, 'sendMessage']);
        Route::post('/{id}/offers', [ChatController::class, 'makeOffer']);
        Route::post('/{id}/offers/{messageId}/accept', [ChatController::class, 'acceptOffer']);
        Route::post('/{id}/offers/{messageId}/reject', [ChatController::class, 'rejectOffer']);
    });

    // ============================================================
    // UMKM OWNER: Admin Jasa + Admin Produk Kuliner/Toko
    // ============================================================
    Route::middleware('role:umkm-owner')->group(function () {

        // -------- PRODUCT CRUD (Kuliner/Toko) --------
        Route::prefix('products')->name('products.')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::get('{product}', [ProductController::class, 'show'])->name('show');
            Route::put('{product}', [ProductController::class, 'update'])->name('update');
            Route::patch('{product}', [ProductController::class, 'update'])->name('update.patch');
            Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');

            Route::patch('{product}/status', [ProductController::class, 'updateStatus'])->name('update-status');
            Route::get('{product}/combinations-count', [ProductController::class, 'getCombinationCount']);

            Route::get('/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('/export/pdf', [ProductController::class, 'exportPdf']);
        });

        // -------- JASA CRUD (Owner) --------
        Route::prefix('jasas')->group(function () {
            Route::get('/owner', [JasaController::class, 'ownerIndex']); // list jasa milik owner
            Route::post('/', [JasaController::class, 'store']);
            Route::get('/{id}', [JasaController::class, 'ownerShow'])->where('id', '[0-9]+'); // detail jasa owner
            Route::put('/{id}', [JasaController::class, 'update'])->where('id', '[0-9]+');
            Route::delete('/{id}', [JasaController::class, 'destroy'])->where('id', '[0-9]+');
        });

        // ✅ Alternative route: POST /merchants/{merchantId}/jasas (owner create for specific merchant)
        Route::post('merchants/{merchantId}/jasas', [JasaController::class, 'store']);

        // ✅ -------- PACKAGES CRUD (Owner) --------
        // list & create by jasa, update/delete by package id
        Route::get('jasas/{jasaId}/packages', [PackageController::class, 'index']);
        Route::post('jasas/{jasaId}/packages', [PackageController::class, 'store']);
        Route::put('packages/{id}', [PackageController::class, 'update']);
        Route::delete('packages/{id}', [PackageController::class, 'destroy']);

        // -------- ORDER MASUK (Owner) --------
        Route::prefix('orders')->group(function () {
            Route::get('/owner', [OrderController::class, 'ownerIndex']);             // list order merchant owner
            Route::get('/owner/{id}', [OrderController::class, 'ownerShow']);         // detail order merchant owner
            Route::patch('/owner/{id}/status', [OrderController::class, 'ownerUpdateStatus']); // update status
        });
    });

    // ============================================================
    // ADMIN ONLY: merchant approval + orders (optional)
    // ============================================================
    Route::middleware('role:admin')->group(function () {
        Route::prefix('merchants')->group(function () {
            Route::post('{merchant}/approve', [MerchantController::class, 'approve']);
            Route::post('{merchant}/reject', [MerchantController::class, 'reject']);
        });

        // Optional: admin lihat semua orders jasa
        Route::get('/admin/orders', [OrderController::class, 'adminIndex']);
        Route::get('/admin/orders/{id}', [OrderController::class, 'adminShow']);
    });

    // -------- COMMUNITY --------
    Route::prefix('community')->group(function () {

        Route::get('/posts/popular', [CommunityPostController::class, 'popular'])
            ->name('community.posts.popular');
        Route::get('/my-posts', [CommunityPostController::class, 'myPosts'])
            ->name('community.posts.my');

        Route::get('/posts', [CommunityPostController::class, 'index'])
            ->name('community.posts.index');
        Route::post('/posts', [CommunityPostController::class, 'store'])
            ->name('community.posts.store');
        Route::get('/posts/{slug}', [CommunityPostController::class, 'show'])
            ->name('community.posts.show');
        Route::put('/posts/{id}', [CommunityPostController::class, 'update'])
            ->name('community.posts.update');
        Route::delete('/posts/{id}', [CommunityPostController::class, 'destroy'])
            ->name('community.posts.destroy');

        Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])
            ->name('community.comments.index');
        Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])
            ->name('community.comments.store');
        Route::post('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'reply'])
            ->name('community.comments.reply');
        Route::delete('/posts/{postId}/comments/{commentId}', [PostCommentController::class, 'destroy'])
            ->name('community.comments.destroy');

        Route::get('/posts/{postId}/comments/{commentId}/replies', [PostCommentController::class, 'getReplies'])
            ->name('community.comments.replies');
        Route::get('/my-comments', [PostCommentController::class, 'myComments'])
            ->name('community.comments.my-comments');
    });
});
