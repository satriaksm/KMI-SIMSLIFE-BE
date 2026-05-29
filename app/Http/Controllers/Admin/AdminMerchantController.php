<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\MerchantApplicationStatusNotification;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminMerchantController extends Controller
{
    /**
     * List Merchants (Admin)
     *
     * Returns a paginated list of merchants with optional filters and search.
     *
     * @authenticated
     *
     * @queryParam status string Filter by merchant status. Example: approved
     * @queryParam segmentation_id integer Filter by segmentation ID. Example: 2
     * @queryParam search string Search by merchant name or user name/email. Example: John
     * @queryParam per_page integer Number of merchants per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Toko Sukses",
     *       "slug": "toko-sukses",
     *       "status": "approved",
     *       "logo_path": null,
     *       "description": "Toko sembako terlengkap",
     *       "user": {
     *         "id": 2,
     *         "name": "John Doe",
     *         "email": "john@example.com",
     *         "phone": "08123456789"
     *       },
     *       "segmentation": {
     *         "id": 1,
     *         "name": "UMKM"
     *       },
     *       "primary_address": { ... },
     *       "products_count": 12,
     *       "created_at": "2025-12-01T10:00:00.000000Z"
     *     }
     *   ],
     *   "meta": {
     *     "current_page": 1,
     *     "per_page": 15,
     *     "total": 50,
     *     "last_page": 4
     *   }
     * }
     */
    /**
     * ADMIN: List all merchants with filters
     */
    public function index(Request $request)
    {
        $query = Merchant::with([
            'user:id,name,email,phone',
            'segmentation:id,name',
            'primaryAddress',
        ])
            ->withCount('products');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by segmentation
        if ($request->filled('segmentation_id')) {
            $query->where('segmentation_id', $request->segmentation_id);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($qu) use ($search) {
                        $qu->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $merchants = $query->latest()
            ->paginate($request->input('per_page', 15));

        // Transform for UI (like AdminUserController)
        $merchants->getCollection()->transform(function ($merchant) {
            return [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'status' => $merchant->status,
                'logo_path' => $merchant->logo_path,
                'description' => $merchant->description,
                'user' => $merchant->user ? [
                    'id' => $merchant->user->id,
                    'name' => $merchant->user->name,
                    'email' => $merchant->user->email,
                    'phone' => $merchant->user->phone,
                ] : null,
                'segmentation' => $merchant->segmentation ? [
                    'id' => $merchant->segmentation->id,
                    'name' => $merchant->segmentation->name,
                ] : null,
                'primary_address' => $merchant->primaryAddress,
                'products_count' => $merchant->products_count ?? 0,
                'created_at' => $merchant->created_at,
            ];
        });

        return response()->json($merchants);
    }

    /**
     * Create Merchant (Admin)
     *
     * Create a new merchant and approve it immediately. Assigns "umkm-owner" role to the user.
     *
     * @authenticated
     *
     * @bodyParam user_id integer required The user ID to assign as owner. Example: 2
     * @bodyParam name string required Merchant name. Example: Toko Sukses
     * @bodyParam phone string required Merchant phone number. Example: 08123456789
     * @bodyParam description string Merchant description. Example: Toko sembako terlengkap
     * @bodyParam segmentation_id integer required Segmentation ID. Example: 1
     * @bodyParam address object required Address object.
     * @bodyParam address.province_id integer required Province ID. Example: 11
     * @bodyParam address.city_id integer required City ID. Example: 1101
     * @bodyParam address.district_id integer required District ID. Example: 110101
     * @bodyParam address.village_id integer required Village ID. Example: 11010101
     * @bodyParam address.detail string Address detail. Example: Jl. Mawar No. 1
     * @bodyParam address.latitude number Latitude. Example: -6.200000
     * @bodyParam address.longitude number Longitude. Example: 106.816666
     *
     * @response 201 {
     *   "message": "Merchant berhasil dibuat & langsung di-approve.",
     *   "merchant": {
     *     "id": 1,
     *     "name": "Toko Sukses",
     *     "status": "approved",
     *     "user_id": 2,
     *     "segmentation": { ... },
     *     "primary_address": { ... }
     *   }
     * }
     *
     * @response 403 {
     *   "message": "Akses ditolak."
     * }
     * @response 422 {
     *   "errors": {
     *     "user_id": ["The user id field is required."],
     *     ...
     *   }
     * }
     */
    public function store(Request $request)
    {
        $admin = $request->user();
        if (!$admin->hasRole('admin')) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^[0-9+\-()\s]+$/'],
            'description' => ['nullable', 'string'],
            'segmentation_id' => ['required', 'integer', 'exists:segmentations,id'],
            'address.province_id' => ['required', 'integer', 'exists:provinces,id'],
            'address.city_id' => ['required', 'integer', 'exists:cities,id'],
            'address.district_id' => ['required', 'integer', 'exists:districts,id'],
            'address.village_id' => ['required', 'integer', 'exists:villages,id'],
            'address.detail' => ['nullable', 'string', 'max:500'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $merchant = DB::transaction(function () use ($validated, $admin) {
            $merchant = Merchant::create([
                'user_id' => $validated['user_id'],
                'paguyuban_id' => null,
                'segmentation_id' => $validated['segmentation_id'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'phone' => $validated['phone'],
                'logo_path' => null,
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'response_at' => now(),
            ]);

            $addr = $validated['address'];
            $merchant->addresses()->create([
                'province_id' => $addr['province_id'],
                'city_id' => $addr['city_id'],
                'district_id' => $addr['district_id'],
                'village_id' => $addr['village_id'],
                'detail' => $addr['detail'] ?? null,
                'label' => 'utama',
                'latitude' => $addr['latitude'] ?? null,
                'longitude' => $addr['longitude'] ?? null,
            ]);

            // Beri role umkm-owner ke user
            $owner = $merchant->user;
            if ($owner) {
                $roleId = DB::table('roles')->where('name', 'umkm-owner')->value('id');
                if (!$roleId) {
                    $roleId = DB::table('roles')->insertGetId([
                        'name' => 'umkm-owner',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                DB::table('role_user')->updateOrInsert(
                    ['user_id' => $owner->id, 'role_id' => $roleId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            return $merchant;
        });

        return response()->json([
            'message' => 'Merchant berhasil dibuat & langsung di-approve.',
            'merchant' => $merchant->load(['segmentation', 'primaryAddress']),
        ], 201);
    }

    /**
     * ADMIN: Get single merchant detail
     */
    /**
     * Get Merchant Detail (Admin)
     *
     * Get detail of a merchant by ID, including user, segmentation, addresses, products, vouchers, and events.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the merchant. Example: 1
     *
     * @response 200 {
     *   "data": {
     *     "id": 1,
     *     "name": "Toko Sukses",
     *     "user": { ... },
     *     "segmentation": { ... },
     *     "addresses": [ ... ],
     *     "products": [ ... ],
     *     "vouchers": [ ... ],
     *     "events": [ ... ]
     *   }
     * }
     * @response 404 {
     *   "message": "Failed to fetch merchant detail"
     * }
     */
    public function show($id)
    {
        try {
            $merchant = Merchant::with([
                'user',
                'segmentation',
                'paguyuban',
                'primaryAddress.province',
                'primaryAddress.city',
                'primaryAddress.district',
                'primaryAddress.village',
                'products.categories', // ✅ Already exists
                'products.variants', // ✅ ADD: Load variants untuk ambil SKU, price, stock
                'vouchers',
                'events',
            ])
                ->withCount(['products', 'vouchers', 'events'])
                ->findOrFail($id);

            // ✅ FIX: Transform products to include complete data from variants
            $merchant->products->transform(function ($product) {
                // Ambil data dari variants
                $variants = $product->variants;

                // Untuk single variant, ambil data pertama
                $firstVariant = $variants->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'sku' => $firstVariant ? $firstVariant->sku : null, // ✅ Dari variant pertama
                    'price' => $variants->isNotEmpty() ? $variants->min('price') : null, // ✅ Min price dari variants
                    'stock' => $variants->sum('stock'), // ✅ Total stock dari semua variants
                    'status' => $product->status,
                    'categories' => $product->categories->map(function ($category) {
                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                        ];
                    }),
                    'created_at' => $product->created_at,
                ];
            });

            // Additional aggregated data
            $aggregatedData = [
                'total_active_vouchers' => $merchant->vouchers()->where('voucher_status', 'active')->count(),
                'total_expired_vouchers' => $merchant->vouchers()->where('voucher_status', 'expired')->count(),
                'total_published_products' => $merchant->products()->where('status', 'published')->count(),
                'total_draft_products' => $merchant->products()->where('status', 'draft')->count(),
                'total_active_events' => $merchant->events()->wherePivot('status', 'accepted')->count(),
            ];

            return response()->json([
                'data' => array_merge(
                    $merchant->toArray(),
                    ['aggregated' => $aggregatedData]
                )
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminMerchant] Show detail failed', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);

            return response()->json([
                'message' => 'Failed to fetch merchant detail',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    // Admin menyetujui pendaftaran -> status approved + beri role "umkm-owner"
    /**
     * Approve Merchant (Admin)
     *
     * Approve a merchant registration and assign "umkm-owner" role to the user.
     *
     * @authenticated
     *
     * @urlParam merchant integer required The ID of the merchant. Example: 1
     *
     * @response 200 {
     *   "message": "Merchant disetujui dan slug telah digenerate.",
     *   "merchant": { ... }
     * }
     * @response 403 {
     *   "message": "Akses ditolak."
     * }
     * @response 422 {
     *   "message": "Merchant sudah disetujui."
     * }
     */
    public function approve(Request $request, Merchant $merchant, WebPushService $webPushService)
    {
        // Validasi role admin - Using hasRole helper for safety
        $admin = $request->user();
        if (!$admin->hasRole('admin')) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($merchant->status === 'approved') {
            return response()->json(['message' => 'Merchant sudah disetujui.'], 422);
        }
        if ($merchant->status === 'rejected') {
            return response()->json(['message' => 'Merchant sudah ditolak.'], 422);
        }

        DB::transaction(function () use ($merchant) {
            // Ensure slug exists (safety check)
            if (empty($merchant->slug)) {
                $merchant->slug = Merchant::generateUniqueSlug($merchant->name);
            }

            $merchant->update([
                'status' => 'approved',
                'response_at' => Carbon::now(),
            ]);

            // Beri role "umkm-owner"
            $owner = $merchant->user;
            if ($owner) {
                $roleId = DB::table('roles')->where('name', 'umkm-owner')->value('id');
                if (!$roleId) {
                    $roleId = DB::table('roles')->insertGetId([
                        'name' => 'umkm-owner',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Use firstOrCreate untuk avoid duplicate entry
                DB::table('role_user')->updateOrInsert(
                    ['user_id' => $owner->id, 'role_id' => $roleId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        });

        if ($merchant->user) {
            $merchant->user->notify(new MerchantApplicationStatusNotification(
                $merchant->fresh(),
                'approved'
            ));

            $webPushService->sendMerchantApplicationDecision(
                $merchant->user,
                $merchant->fresh(),
                'approved'
            );
        }

        return response()->json([
            'message' => 'Merchant disetujui dan slug telah digenerate.',
            'merchant' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
        ]);
    }

    // Admin menolak pendaftaran -> status rejected
    /**
     * Reject Merchant (Admin)
     *
     * Reject a merchant registration.
     *
     * @authenticated
     *
     * @urlParam merchant integer required The ID of the merchant. Example: 1
     *
     * @response 200 {
     *   "message": "Merchant ditolak.",
     *   "merchant": { ... }
     * }
     * @response 403 {
     *   "message": "Akses ditolak."
     * }
     * @response 422 {
     *   "message": "Merchant sudah ditolak."
     * }
     */
    public function reject(Request $request, Merchant $merchant, WebPushService $webPushService)
    {
        $admin = $request->user();
        // Using hasRole helper for safety
        if (!$admin->hasRole('admin')) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($merchant->status === 'approved') {
            return response()->json(['message' => 'Merchant sudah disetujui, tidak bisa ditolak.'], 422);
        }
        if ($merchant->status === 'rejected') {
            return response()->json(['message' => 'Merchant sudah ditolak.'], 422);
        }

        $merchant->update([
            'status' => 'rejected',
            'response_at' => Carbon::now(),
        ]);

        if ($merchant->user) {
            $merchant->user->notify(new MerchantApplicationStatusNotification(
                $merchant->fresh(),
                'rejected'
            ));

            $webPushService->sendMerchantApplicationDecision(
                $merchant->user,
                $merchant->fresh(),
                'rejected'
            );
        }

        return response()->json([
            'message' => 'Merchant ditolak.',
            'merchant' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
        ]);
    }

    /**
     * Merchant Statistics (Admin)
     *
     * Get merchant statistics: total orders per day (last 30 days), product orders per category (last 30 days).
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the merchant. Example: 1
     *
     * @response 200 {
     *   "orders": [
     *     { "date": "2025-12-01", "total": 5 },
     *     ...
     *   ],
     *   "product_orders": [
     *     { "category": "Sembako", "total": 12 },
     *     ...
     *   ]
     * }
     */
    public function statistics($id)
    {
        // Total transaksi (orders) 30 hari terakhir (per hari)
        $orders = Order::where('merchant_id', $id)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Produk terorder per kategori 30 hari terakhir
        $productOrders = OrderItem::whereHas('order', function ($q) use ($id) {
            $q->where('merchant_id', $id)
                ->where('created_at', '>=', now()->subDays(30));
        })
            ->with('product.categories')
            ->get()
            ->flatMap(function ($item) {
                // Ambil semua kategori dari produk
                return $item->product->categories->map(function ($cat) use ($item) {
                    return [
                        'category' => $cat->name,
                        'qty' => $item->quantity,
                    ];
                });
            })
            ->groupBy('category')
            ->map(function ($items, $cat) {
                return [
                    'category' => $cat,
                    'total' => collect($items)->sum('qty'),
                ];
            })
            ->values();

        return response()->json([
            'orders' => $orders,
            'product_orders' => $productOrders,
        ]);
    }

    /**
     * Export Merchants to PDF (Admin)
     *
     * Export list of merchants to PDF with filters.
     *
     * @authenticated
     *
     * @queryParam status string Filter by status. Example: approved
     * @queryParam segmentation_id integer Filter by segmentation. Example: 1
     * @queryParam search string Search query. Example: Toko
     *
     * @response 200 application/pdf
     */
    public function exportPdf(Request $request)
    {
        try {
            $admin = $request->user();

            // Build query with same filters as index
            $query = Merchant::with([
                'user:id,name,email,phone',
                'segmentation:id,name',
                'primaryAddress',
            ])->withCount('products');

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('segmentation_id')) {
                $query->where('segmentation_id', $request->segmentation_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($qu) use ($search) {
                            $qu->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            $merchants = $query->latest()->limit(500)->get();

            // Metadata
            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $admin->name ?? 'Admin',
                'generated_by_email' => $admin->email ?? '-',
                'total_merchants' => $merchants->count(),
                'filters' => [
                    'status' => $request->input('status') ?: 'Semua',
                    'segmentation' => $request->input('segmentation_id') ?: 'Semua',
                    'search' => $request->input('search') ?: '-',
                ],
            ];

            // Load logo as base64
            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';

            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            }

            // Generate PDF
            $pdf = Pdf::loadView('exports.admin.admin-merchant', [
                'merchants' => $merchants,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'landscape')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'merchants-report-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminMerchant] Export PDF failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan PDF',
            ], 500);
        }
    }

    /**
     * Export Merchant Detail to PDF (Admin)
     *
     * Export single merchant detail to PDF.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the merchant. Example: 1
     *
     * @response 200 application/pdf
     */
    public function exportMerchantDetailPdf(Request $request, $id)
    {
        try {
            $admin = $request->user();

            // ✅ FIX: Load merchant dengan relasi yang sama seperti show()
            $merchant = Merchant::with([
                'user',
                'segmentation',
                'paguyuban',
                'primaryAddress.province',
                'primaryAddress.city',
                'primaryAddress.district',
                'primaryAddress.village',
                'products.categories',
                'products.variants', // ✅ ADD: Load variants untuk ambil SKU, price, stock
                'vouchers',
                'events',
            ])
                ->withCount(['products', 'vouchers', 'events'])
                ->findOrFail($id);

            // ✅ FIX: Transform products data (sama seperti di show())
            $merchant->products->transform(function ($product) {
                // Ambil data dari variants
                $variants = $product->variants;

                // Untuk single variant, ambil data pertama
                $firstVariant = $variants->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'sku' => $firstVariant ? $firstVariant->sku : null, // ✅ Dari variant pertama
                    'price' => $variants->isNotEmpty() ? $variants->min('price') : null, // ✅ Min price dari variants
                    'stock' => $variants->sum('stock'), // ✅ Total stock dari semua variants
                    'status' => $product->status,
                    'categories' => $product->categories->map(function ($category) {
                        return [
                            'id' => $category->id,
                            'name' => $category->name,
                        ];
                    })->toArray(),
                    'created_at' => $product->created_at,
                ];
            });

            // Get statistics
            try {
                $stats = DB::table('orders')
                    ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                    ->join('products', 'order_items.product_id', '=', 'products.id')
                    ->where('products.merchant_id', $merchant->id)
                    ->where('orders.created_at', '>=', now()->subDays(30))
                    ->selectRaw('
                        COUNT(DISTINCT orders.id) as total_orders,
                        SUM(order_items.quantity * order_items.price) as total_revenue
                    ')
                    ->first();

                $statistics = [
                    'total_orders' => $stats->total_orders ?? 0,
                    'total_revenue' => $stats->total_revenue ?? 0,
                ];
            } catch (\Exception $e) {
                Log::warning('[AdminMerchant] Statistics query failed: ' . $e->getMessage());
                $statistics = [
                    'total_orders' => 0,
                    'total_revenue' => 0,
                ];
            }

            // Metadata
            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $admin->name ?? 'Admin',
                'generated_by_email' => $admin->email ?? '-',
            ];

            // Load logo
            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';

            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            }

            // Generate PDF
            $pdf = Pdf::loadView('exports.admin.admin-merchant-detail', [
                'merchant' => $merchant,
                'statistics' => $statistics,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'portrait')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'merchant-detail-' . $merchant->id . '-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminMerchant] Export merchant detail PDF failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan PDF',
            ], 500);
        }
    }

    /**
     * Stream merchant logo image
     */
    public function showLogo(Request $request, Merchant $merchant)
    {
        // Support signed URL for secure access
        if ($request->hasValidSignature()) {
            return $this->streamMerchantLogo($merchant);
        }

        // Public access for now (you can add auth checks later)
        return $this->streamMerchantLogo($merchant);
    }

    /**
     * Private method to stream merchant logo
     */
    private function streamMerchantLogo(Merchant $merchant)
    {
        if (empty($merchant->logo_path)) {
            abort(404);
        }

        $disk = 'public';
        $path = ltrim($merchant->logo_path, '/');

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        $stream = Storage::disk($disk)->readStream($path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
