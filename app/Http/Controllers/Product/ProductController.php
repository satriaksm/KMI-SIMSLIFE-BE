<?php

namespace App\Http\Controllers\Product;

use App\Models\Image;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Merchant;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Exports\ProductsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Intervention\Image\ImageManager as ImageIntervention;

class ProductController
{
    private const ALLOWED_SEGMENT_IDS = [1, 2];
    private const MAX_VARIANT_COMBINATIONS = 50;
    private const MAX_VARIANTS = 2;
    private const MAX_ADDON_GROUPS = 10;
    private const MAX_ADDON_GROUP_OPTIONS = 10;

    // ============================================================
    // PUBLIC ENDPOINTS (No Auth Required)
    // ============================================================

    /**
     * Public: List published products (e-commerce catalog)
     * No authentication required
     */
    public function publicIndex(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc,name_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()
            ->where('status', 'published')
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with([
                'coverImage',
                'merchant:id,name,slug',
                'categories:id,name',
            ]);

        // Search by name
        if (!empty($data['q'])) {
            $query->where('name', 'like', '%' . $data['q'] . '%');
        }

        // Filter by merchant
        if (!empty($data['merchant_id'])) {
            $query->where('merchant_id', $data['merchant_id']);
        }

        // Filter by category
        if (!empty($data['category_id'])) {
            $query->whereHas('categories', fn($q) => $q->where('categories.id', $data['category_id']));
        }

        // Filter by price range (from cheapest variant)
        if (isset($data['min_price']) || isset($data['max_price'])) {
            $query->whereHas('variants', function ($q) use ($data) {
                if (isset($data['min_price'])) {
                    $q->where('price', '>=', $data['min_price']);
                }
                if (isset($data['max_price'])) {
                    $q->where('price', '<=', $data['max_price']);
                }
            });
        }

        // Sorting
        switch ($data['sort'] ?? 'newest') {
            case 'price_asc':
                $query->leftJoin('product_variants as pv', 'products.id', '=', 'pv.product_id')
                    ->selectRaw('products.*, MIN(pv.price) as min_price')
                    ->groupBy('products.id')
                    ->orderBy('min_price', 'asc');
                break;
            case 'price_desc':
                $query->leftJoin('product_variants as pv', 'products.id', '=', 'pv.product_id')
                    ->selectRaw('products.*, MAX(pv.price) as max_price')
                    ->groupBy('products.id')
                    ->orderBy('max_price', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('name', 'desc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $perPage = $data['per_page'] ?? 20;

        return response()->json($query->paginate($perPage));
    }

    public function publicIndexToko(Request $request)
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $data['limit'] ?? 12;

        // Ambil produk dari merchant dengan segmentation_id = 2 (Toko)
        $products = Product::where('status', 'published')
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved')
                    ->where('segmentation_id', 1); // Toko
            })
            ->with([
                'coverImage',
                'merchant:id,name,slug',
                'categories:id,name',
            ])
            ->withCount('variants')
            ->addSelect([
                'products.*',
                'min_price' => function ($q) {
                    $q->selectRaw('MIN(price)')
                        ->from('product_variants')
                        ->whereColumn('product_id', 'products.id');
                },
                'max_price' => function ($q) {
                    $q->selectRaw('MAX(price)')
                        ->from('product_variants')
                        ->whereColumn('product_id', 'products.id');
                },
                'total_stock' => function ($q) {
                    $q->selectRaw('COALESCE(SUM(stock), 0)')
                        ->from('product_variants')
                        ->whereColumn('product_id', 'products.id');
                },
            ])
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $products,
            'count' => $products->count(),
        ]);
    }

    /**
     * Public: Get random products for Kuliner homepage
     * No authentication required
     */
    public function publicIndexKuliner(Request $request)
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = $data['limit'] ?? 12;

        // Ambil produk dari merchant dengan segmentation_id = 1 (Kuliner)
        $products = Product::where('status', 'published')
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved')
                    ->where('segmentation_id', 2); // Kuliner
            })
            ->with([
                'coverImage',
                'merchant:id,name,slug',
                'categories:id,name',
            ])
            ->withCount('variants')
            ->addSelect([
                'products.*',
                'min_price' => function ($q) {
                    $q->selectRaw('MIN(price)')
                        ->from('product_variants')
                        ->whereColumn('product_id', 'products.id');
                },
                'max_price' => function ($q) {
                    $q->selectRaw('MAX(price)')
                        ->from('product_variants')
                        ->whereColumn('product_id', 'products.id');
                },
                'total_stock' => function ($q) {
                    $q->selectRaw('COALESCE(SUM(stock), 0)')
                        ->from('product_variants')
                        ->whereColumn('product_id', 'products.id');
                },
            ])
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $products,
            'count' => $products->count(),
        ]);
    }

    /**
     * Public: Get product detail by slug (PDP - Product Detail Page)
     * No authentication required
     */
    public function publicShow(string $slug)
    {
        $product = Product::where('slug', $slug)
            ->whereIn('status', ['published', 'archived'])
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with([
                // Images
                'coverImage',
                'images' => fn($q) => $q->orderBy('display_order'),

                // Merchant & categories
                'merchant:id,name,slug,description,phone',
                'merchant.primaryAddress',
                'merchant.primaryAddress.province:id,name',
                'merchant.primaryAddress.city:id,name',
                'merchant.primaryAddress.district:id,name',
                'merchant.primaryAddress.village:id,name',
                'categories:id,name,slug',

                // Options dengan option_name dan values
                'options' => function ($q) {
                    $q->with([
                        'values' => fn($vq) => $vq
                            ->select('id', 'product_option_id', 'option_value', 'image_path')
                    ])
                        ->select('id', 'product_id', 'option_name', 'uses_image')
                        ->orderBy('id');
                },

                // Variants lengkap + optionValues dengan option_name
                'variants' => function ($q) {
                    $q->with([
                        'optionValues' => function ($ovq) {
                            $ovq->select('product_option_values.id', 'product_option_values.product_option_id', 'product_option_values.option_value')
                                ->join('product_options', 'product_option_values.product_option_id', '=', 'product_options.id')
                                ->addSelect('product_options.option_name');
                        }
                    ])
                        ->select('id', 'product_id', 'stock', 'price', 'sku')
                        ->orderBy('price', 'asc');
                },

                // Addon groups
                'addonGroups' => function ($q) {
                    $q->with([
                        'options' => function ($oq) {
                            $oq->with('addon:id,addon_name')
                                ->select('id', 'addon_group_id', 'addon_id', 'addon_price')
                            ;
                        }
                    ])
                        ->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection')
                        ->orderBy('id');
                },
            ])
            ->firstOrFail();

        // Range harga dari variants
        $variants = $product->variants;
        $priceRange = [
            'min' => $variants->min('price'),
            'max' => $variants->max('price'),
        ];

        // Opsi 1 dan 2 (maksimal 2 opsi)
        $option1 = optional($product->options)->get(0);
        $option2 = optional($product->options)->get(1);

        // Kombinasi harga & stok per variant, pakai id option_value (sizeId & variantId)
        $combinations = [];
        foreach ($variants as $v) {
            $ov = collect($v->optionValues ?? []);
            $opt1Val = $ov->firstWhere('product_option_id', optional($option1)->id);
            $opt2Val = $ov->firstWhere('product_option_id', optional($option2)->id);

            $combinations[] = [
                'product_variant_id' => $v->id,
                'sizeId' => $opt1Val->id ?? 0,
                'variantId' => $opt2Val->id ?? 0,
                'price' => (float) $v->price,
                'stock' => (int) $v->stock,
                'sku' => $v->sku,
            ];
        }

        // Minimal pembelian
        $minPurchase = (int) ($product->min_purchase ?? 1);

        $addr = $product->merchant?->primaryAddress;
        $merchantAddress = $addr?->full_address
            ?? implode(', ', array_filter([
                $addr?->detail,
                $addr?->village?->name,
                $addr?->district?->name,
                $addr?->city?->name,
                $addr?->province?->name,
            ]));

        // Ambil 5 produk lain dari merchant yang sama, acak, exclude produk ini
        $relatedProducts = Product::where('merchant_id', $product->merchant_id)
            ->where('id', '!=', $product->id)
            ->where('status', 'published')
            ->with(['coverImage'])
            ->inRandomOrder()
            ->limit(5)
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'min_price' => $p->variants->min('price'),
                    'max_price' => $p->variants->max('price'),
                    'merchant' => [
                        'id' => $p->merchant->id,
                        'name' => $p->merchant->name,
                        'slug' => $p->merchant->slug,
                    ],
                    'cover_image' => $p->coverImage,
                ];
            })
            ->values();

        return response()->json([
            'product' => $product,              // berisi images, options(+values), variants(+optionValues dgn option_name), addonGroups(+options+addon)
            'price_range' => $priceRange,       // min & max price dari variants
            'total_stock' => $variants->sum('stock'),
            'has_variants' => $variants->isNotEmpty(),
            'has_addons' => $product->addonGroups->isNotEmpty(),
            'combinations' => $combinations,    // daftar kombinasi harga & stok per variant (sizeId, variantId)
            'option_labels' => [
                'option1' => $option1 ? $option1->option_name : null,
                'option2' => $option2 ? $option2->option_name : null,
            ],
            'min_purchase' => $minPurchase,     // minimal beli
            'merchant_address' => $merchantAddress,
            'related_products' => $relatedProducts, // ✅ produk lain di toko ini
        ]);
    }

    /**
     * Public: Get variant by selected option values
     * Real-time availability check when user selects options
     */
    public function publicGetVariant(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)
            ->whereIn('status', ['published', 'archived']) // boleh cek varian walau produk di-archive
            ->firstOrFail();

        $data = $request->validate([
            'option_value_ids' => ['required', 'array', 'min:1'],
            'option_value_ids.*' => ['integer', 'exists:product_option_values,id'],
        ]);

        $optionValueIds = $data['option_value_ids'];
        sort($optionValueIds);

        // Find exact combination
        $variant = $product->variants()
            ->select('product_variants.*')
            ->join('product_variant_option_values as pvov', 'product_variants.id', '=', 'pvov.product_variant_id')
            ->whereIn('pvov.product_option_value_id', $optionValueIds)
            ->groupBy('product_variants.id')
            ->havingRaw('COUNT(DISTINCT pvov.product_option_value_id) = ?', [count($optionValueIds)])
            ->with('optionValues:id,option_value,image_path')
            ->first();

        // always include product-level min_purchase so frontend knows the rule
        $minPurchase = (int) ($product->min_purchase ?? 1);

        if (!$variant) {
            return response()->json([
                'message' => 'Variant dengan kombinasi ini tidak tersedia.',
                'available' => false,
                'min_purchase' => $minPurchase,
            ], 404);
        }

        if ($variant->stock <= 0) {
            return response()->json([
                'message' => 'Variant ini sedang habis.',
                'available' => false,
                'variant' => $variant->only(['id', 'price', 'stock', 'sku']),
                'min_purchase' => $minPurchase,
            ], 200);
        }

        return response()->json([
            'available' => true,
            'variant' => [
                'id' => $variant->id,
                'price' => $variant->price,
                'stock' => $variant->stock,
                'sku' => $variant->sku,
                'option_values' => $variant->optionValues,
            ],
            // sertakan min_purchase di response utama
            'min_purchase' => $minPurchase,
        ]);
    }


    /**
     * Public: Get products by merchant slug (merchant catalog)
     */
    public function publicByMerchant(Request $request, string $merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)
            ->where('status', 'approved')
            ->firstOrFail(['id', 'name', 'slug', 'description']);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::where('merchant_id', $merchant->id)
            ->whereIn('status', ['published', 'archived'])
            ->with(['coverImage', 'categories:id,name']);

        if (!empty($data['q'])) {
            $query->where('name', 'like', '%' . $data['q'] . '%');
        }

        if (!empty($data['category_id'])) {
            $query->whereHas('categories', fn($q) => $q->where('categories.id', $data['category_id']));
        }

        switch ($data['sort'] ?? 'newest') {
            case 'price_asc':
                $query->leftJoin('product_variants as pv', 'products.id', '=', 'pv.product_id')
                    ->selectRaw('products.*, MIN(pv.price) as min_price')
                    ->groupBy('products.id')
                    ->orderBy('min_price', 'asc');
                break;
            case 'price_desc':
                $query->leftJoin('product_variants as pv', 'products.id', '=', 'pv.product_id')
                    ->selectRaw('products.*, MAX(pv.price) as max_price')
                    ->groupBy('products.id')
                    ->orderBy('max_price', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $perPage = $data['per_page'] ?? 20;

        return response()->json([
            'merchant' => $merchant,
            'products' => $query->paginate($perPage),
        ]);
    }

    /**
     * Public: Get featured products (for homepage)
     */
    public function publicFeatured()
    {
        $products = Product::whereIn('status', ['published', 'archived'])
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with(['coverImage', 'merchant:id,name'])
            ->inRandomOrder()
            ->limit(12)
            ->get();

        return response()->json($products);
    }

    // ============================================================
    // PROTECTED ENDPOINTS (Auth Required - Merchant Owner)
    // ============================================================

    /**
     * ✅ UPDATED: List products by merchant (auto-detect merchant dari user)
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0', 'gte:min_stock'],
            'sort_by' => ['nullable', 'in:newest,oldest,name_asc,name_desc,price_asc,price_desc,stock_asc,stock_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'max_price.gte' => 'Harga maksimal harus lebih besar atau sama dengan harga minimal',
            'max_stock.gte' => 'Stok maksimal harus lebih besar atau sama dengan stok minimal',
        ]);

        // Auto-detect merchant
        if (empty($data['merchant_id'])) {
            $merchant = Merchant::where('user_id', $request->user()->id)
                ->where('status', 'approved')
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();

            if (!$merchant) {
                return response()->json([
                    'message' => 'Anda belum memiliki UMKM.',
                ], 403);
            }

            $merchantId = $merchant->id;
        } else {
            $merchantOrError = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
            if (is_array($merchantOrError) && isset($merchantOrError['error'])) {
                return $merchantOrError['error'];
            }
            $merchantId = $merchantOrError->id;
        }

        // Build query using centralized helper
        $query = $this->buildFilteredProductQuery($merchantId, $data);

        $perPage = $data['per_page'] ?? 10;
        // $perPage = 1;
        $result = $query->paginate($perPage);

        $result->getCollection()->transform(function ($product) {

            // Cek status produk
            $isPublic = in_array($product->status, ['published', 'archived']);

            // 1. Handle Cover Image (Jika diload/ada)
            if ($product->coverImage) {
                $product->coverImage->src_url = $isPublic
                    ? route('images.show', ['image' => $product->coverImage->id])
                    : URL::signedRoute('images.show', ['image' => $product->coverImage->id], now()->addMinutes(60));
            }

            // 2. Handle Array Images (Jika diload/ada)
            if ($product->images) {
                $product->images->transform(function ($image) use ($isPublic) {
                    $image->src_url = $isPublic
                        ? route('images.show', ['image' => $image->id])
                        : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));
                    return $image;
                });
            }

            return $product;
        });

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'from' => $result->firstItem(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'to' => $result->lastItem(),
                'total' => $result->total(),
            ],
            'links' => [
                'first' => $result->url(1),
                'last' => $result->url($result->lastPage()),
                'prev' => $result->previousPageUrl(),
                'next' => $result->nextPageUrl(),
            ],
            'applied_filters' => [
                'search' => $data['q'] ?? null,
                'status' => $data['status'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'min_price' => $data['min_price'] ?? null,
                'max_price' => $data['max_price'] ?? null,
                'min_stock' => $data['min_stock'] ?? null,
                'max_stock' => $data['max_stock'] ?? null,
                'sort_by' => $data['sort_by'] ?? 'newest',
            ],
        ]);
    }

    /**
     * UPDATED: Create product
     * ✅ FIXED: Remove is_required, gunakan min_selection untuk logic required
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // Basic product info
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'min_purchase' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,published,archived'],

            // ✅ Categories (multiple)
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'exists:categories,id'],

            // Images
            'images' => ['nullable', 'array'],
            'images.*.file' => ['required', 'file', 'image', 'max:5120'],
            'images.*.order' => ['required', 'integer', 'min:0'],
            'cover_image_index' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // Variants (optional)
            'variants' => ['nullable', 'array', 'max:' . self::MAX_VARIANTS],
            'variants.*.name' => ['required', 'string', 'max:100'],
            'variants.*.uses_images' => ['required', 'in:0,1'],
            'variants.*.options' => ['required', 'array', 'min:1'],
            'variants.*.options.*.name' => ['required', 'string', 'max:100'],
            'variants.*.options.*.images' => ['nullable', 'array', 'max:1'],
            'variants.*.options.*.images.*.file' => ['file', 'image', 'max:5120'],

            // Combinations (jika pakai variants)
            'combinations' => ['nullable', 'array', 'max:' . self::MAX_VARIANT_COMBINATIONS],
            'combinations.*.combination' => ['required', 'string', 'max:255'],
            'combinations.*.sku' => ['nullable', 'string', 'max:100', 'unique:product_variants,sku'], // ✅ UPDATED: Add unique validation
            'combinations.*.price' => ['required', 'numeric', 'min:0'],
            'combinations.*.stock' => ['required', 'integer', 'min:0', 'max:9999'],
            'combinations.*.attributes' => ['required', 'array'],
            'combinations.*.attributes.*.name' => ['required', 'string'],
            'combinations.*.attributes.*.value' => ['required', 'string'],

            // Addon groups
            'add_on_groups' => ['nullable', 'array', 'max:' . self::MAX_ADDON_GROUPS],
            'add_on_groups.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.min_selection' => ['required', 'integer', 'min:0'],
            'add_on_groups.*.max_selection' => ['required', 'integer', 'min:1'],
            'add_on_groups.*.options' => ['required', 'array', 'min:1', 'max:' . (self::MAX_ADDON_GROUP_OPTIONS)],
            'add_on_groups.*.options.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.options.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        if (count($data['images']) > 6) {
            return response()->json([
                'message' => 'Maksimal upload 6 foto produk.',
            ], 422);
        }
        // Validasi merchant ownership & segment
        $merchantOrError = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
        if (is_array($merchantOrError) && isset($merchantOrError['error'])) {
            return $merchantOrError['error'];
        }
        $merchant = $merchantOrError;

        if (!empty($data['variants'])) {
            $variantNames = collect($data['variants'])
                ->pluck('name')
                ->map(fn($name) => strtolower(trim($name)));

            if ($variantNames->count() !== $variantNames->unique()->count()) {
                return response()->json([
                    'message' => 'Nama varian tidak boleh sama.',
                ], 422);
            }
        }

        // Validasi: jika tidak pakai variants, harus ada price & stock
        $useVariants = !empty($data['variants']);
        if (!$useVariants && (!isset($data['price']) || !isset($data['stock']))) {
            return response()->json([
                'message' => 'Harga dan stok wajib diisi jika tidak menggunakan variasi.',
            ], 422);
        }

        // Validasi: jika pakai variants, harus ada combinations
        if ($useVariants && empty($data['combinations'])) {
            return response()->json([
                'message' => 'Kombinasi variasi wajib diisi jika menggunakan variasi.',
            ], 422);
        }

        // ✅ FIXED: Validasi addon groups
        if (!empty($data['add_on_groups'])) {
            foreach ($data['add_on_groups'] as $groupIndex => $group) {
                // Validasi: min_selection tidak boleh > max_selection
                if ($group['min_selection'] > $group['max_selection']) {
                    return response()->json([
                        'message' => "Grup add-on #{$groupIndex}: minimal pilihan tidak boleh lebih besar dari maksimal.",
                    ], 422);
                }

                // Validasi: max_selection tidak boleh > jumlah opsi
                $optionCount = count($group['options']);
                if ($group['max_selection'] > $optionCount) {
                    return response()->json([
                        'message' => "Grup add-on #{$groupIndex}: maksimal pilihan tidak boleh lebih besar dari jumlah opsi ({$optionCount}).",
                    ], 422);
                }

                // ✅ NEW: Validasi min_selection harus <= jumlah opsi
                if ($group['min_selection'] > $optionCount) {
                    return response()->json([
                        'message' => "Grup add-on #{$groupIndex}: minimal pilihan tidak boleh lebih besar dari jumlah opsi ({$optionCount}).",
                    ], 422);
                }
            }
        }

        // Validasi: combinations tidak boleh duplikat
        if ($useVariants) {
            $combinations = collect($data['combinations']);
            $uniqueCombinations = $combinations->pluck('combination')->unique();

            if ($combinations->count() !== $uniqueCombinations->count()) {
                return response()->json([
                    'message' => 'Kombinasi variasi tidak boleh duplikat.',
                ], 422);
            }
        }

        // ==============================
        // VALIDASI: Nama addon group tidak boleh sama
        // ==============================
        if (!empty($data['add_on_groups'])) {
            $groupNames = collect($data['add_on_groups'])
                ->pluck('name')
                ->map(fn($name) => strtolower(trim($name)));

            if ($groupNames->count() !== $groupNames->unique()->count()) {
                return response()->json([
                    'message' => 'Nama grup add-on tidak boleh sama.',
                ], 422);
            }
        }
        // ==============================
        // VALIDASI: Nama addon option tidak boleh duplikat dalam 1 grup
        // ==============================
        if (!empty($data['add_on_groups'])) {
            foreach ($data['add_on_groups'] as $groupIndex => $group) {
                if (!empty($group['options'])) {
                    $optionNames = collect($group['options'])
                        ->pluck('name')
                        ->map(fn($name) => strtolower(trim($name)));

                    if ($optionNames->count() !== $optionNames->unique()->count()) {
                        return response()->json([
                            'message' => "Nama opsi add-on pada grup '{$group['name']}' tidak boleh sama.",
                        ], 422);
                    }
                }
            }
        }

        DB::beginTransaction();
        try {
            // Create product
            $product = Product::create([
                'merchant_id' => $merchant->id,
                'name' => $data['name'],
                'slug' => $this->generateUniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'min_purchase' => $data['min_purchase'] ?? 1,
                'status' => $data['status'] ?? 'draft',
            ]);

            // ✅ Attach categories
            if (!empty($data['category_ids'])) {
                $product->categories()->attach($data['category_ids']);
            }



            // 3. UPLOAD PRODUCT IMAGES
            $this->storeProductImages($product, $data['images'], $data['cover_image_index']);

            // 4. CREATE VARIANTS OR DIRECT PRICING
            if ($useVariants) {
                // Create options + option values
                $optionMap = $this->createProductOptions($product, $data['variants']);
                $this->createProductVariants($product, $data['combinations'], $optionMap);
            } else {
                // Single variant (no options)
                ProductVariant::create([
                    'product_id' => $product->id,
                    'price' => $data['price'],
                    'stock' => $data['stock'],
                    'sku' => $data['sku'] ?? null,
                ]);
            }

            // Create addon groups
            if (!empty($data['add_on_groups'])) {
                $this->createAddonGroups($product, $merchant, $data['add_on_groups']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Produk berhasil dibuat',
                'data' => $product->load(['categories', 'images', 'variants', 'options.values', 'addonGroups.options.addon']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($product)) {
                $this->cleanupProductFiles($product);
            }
            throw $e;
        }
    }

    /**
     * HELPER: Upload product images
     */
    private function storeProductImages(Product $product, array $images, int $coverIndex): void
    {
        $imagesToInsert = [];
        $now = now();

        // Sort by order
        usort($images, fn($a, $b) => $a['order'] <=> $b['order']);

        foreach ($images as $index => $imageData) {
            $file = $imageData['file'];
            $path = $file->store("products/{$product->id}", 'public');

            $imagesToInsert[] = [
                'imageable_type' => 'product',
                'imageable_id' => $product->id,
                'image_path' => $path,
                'display_order' => $index,
                'is_cover' => ($index === $coverIndex),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('images')->insert($imagesToInsert);
    }

    /**
     * ✅ FIXED: Create product options dengan uses_images integer (0 atau 1)
     */
    private function createProductOptions(Product $product, array $variants): array
    {
        $optionMap = [];

        foreach ($variants as $variantIndex => $variant) {
            // ✅ FIXED: Convert uses_images ke boolean untuk database
            $usesImages = ($variantIndex === 0) && ((int) $variant['uses_images'] === 1);

            // Create option
            $option = $product->options()->create([
                'option_name' => $variant['name'],
                'uses_image' => $usesImages, // boolean: true/false
            ]);

            $valueMap = [];

            foreach ($variant['options'] as $optionData) {
                // Create option value
                $value = $option->values()->create([
                    'option_value' => $optionData['name'],
                ]);

                // Upload image jika ada (hanya untuk option pertama)
                if ($usesImages && !empty($optionData['images'])) {
                    $imageFile = $optionData['images'][0]['file'];
                    $path = $imageFile->store("option-values/{$value->id}", 'public');
                    $value->update(['image_path' => $path]);
                }

                $valueMap[$optionData['name']] = $value->id;
            }

            $optionMap[$variant['name']] = [
                'option_id' => $option->id,
                'values' => $valueMap,
            ];
        }

        return $optionMap;
    }

    /**
     * HELPER: Create product variants (combinations)
     */
    private function createProductVariants(Product $product, array $combinations, array $optionMap): void
    {
        foreach ($combinations as $combo) {
            // Create variant
            $variant = $product->variants()->create([
                'sku' => $combo['sku'] ?? null,
                'price' => $combo['price'],
                'stock' => $combo['stock'],
            ]);

            // Attach option values
            $optionValueIds = [];
            foreach ($combo['attributes'] as $attr) {
                $optionName = $attr['name'];
                $valueName = $attr['value'];

                if (isset($optionMap[$optionName]['values'][$valueName])) {
                    $optionValueIds[] = $optionMap[$optionName]['values'][$valueName];
                }
            }

            if (!empty($optionValueIds)) {
                $variant->optionValues()->attach($optionValueIds);
            }
        }
    }

    /**
     * HELPER: Create addon groups + options
     */
    private function createAddonGroups(Product $product, Merchant $merchant, array $groups): void
    {
        foreach ($groups as $groupData) {
            $groupName = trim($groupData['name']);

            // ✅ EXTRA SAFETY: cek group duplikat di product (case-insensitive)
            $groupExists = $product->addonGroups()
                ->where(DB::raw('LOWER(addon_group_name)'), strtolower($groupName))
                ->exists();

            if ($groupExists) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'add_on_groups' => "Nama grup add-on '{$groupName}' tidak boleh sama.",
                ]);
            }

            $selectionType = ($groupData['max_selection'] === 1) ? 'single' : 'multiple';

            $addonGroup = $product->addonGroups()->create([
                'addon_group_name' => $groupName,
                'selection_type' => $selectionType,
                'min_selection' => $groupData['min_selection'],
                'max_selection' => $groupData['max_selection'],
            ]);

            $usedOptionNames = [];

            foreach ($groupData['options'] as $optionData) {
                $optionName = strtolower(trim($optionData['name']));

                // ✅ EXTRA SAFETY: cek option duplikat dalam group
                if (in_array($optionName, $usedOptionNames)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'add_on_groups' =>
                        "Nama opsi '{$optionData['name']}' pada grup '{$groupName}' tidak boleh sama.",
                    ]);
                }

                $usedOptionNames[] = $optionName;

                $addon = $merchant->addons()->firstOrCreate(
                    ['addon_name' => $optionData['name']],
                    ['addon_name' => $optionData['name']]
                );

                $addonGroup->options()->create([
                    'addon_id' => $addon->id,
                    'addon_price' => $optionData['price'],
                ]);
            }
        }
    }


    /**
     * HELPER: Generate unique slug
     */
    private function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $count = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = Str::slug($name) . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * HELPER: Cleanup uploaded files on error
     */
    private function cleanupProductFiles(Product $product): void
    {
        $disk = config('filesystems.product_disk', 'public');
        // gunakan morphClass
        $imageableType = $product->getMorphClass();

        $images = Image::where('imageable_type', $imageableType)
            ->where('imageable_id', $product->id)
            ->get();

        foreach ($images as $image) {
            $this->deleteImageFileIfExists($image->image_path);
            $image->delete();
        }

        // Delete option value images
        $optionValues = $product->options()
            ->with('values')
            ->get()
            ->pluck('values')
            ->flatten();

        foreach ($optionValues as $value) {
            if ($value->image_path) {
                $this->deleteImageFileIfExists($value->image_path);
                // Optionally: $value->update(['image_path' => null]);
            }
        }

        // Delete product folder (if using local disk)
        if (Storage::disk($disk)->exists("products/{$product->id}")) {
            Storage::disk($disk)->deleteDirectory("products/{$product->id}");
        }
    }


    // ============================================================
    // PROTECTED ENDPOINTS (Auth Required - Merchant Owner)
    // ============================================================

    /**
     * ✅ FIXED: Get product (owner only) - WITH ADDONS COMPLETE
     * Use slug instead of id
     */
    public function show(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;
        $product->load([
            'coverImage',
            'images' => fn($q) => $q->orderBy('display_order'),
            'categories',
            'options' => function ($q) {
                $q->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')])
                    ->select('id', 'product_id', 'option_name', 'uses_image')
                    ->orderBy('id');
            },
            'variants' => function ($q) {
                $q->with([
                    'optionValues' => function ($ovq) {
                        $ovq->select('product_option_values.id', 'product_option_id', 'option_value')
                            ->join('product_options', 'product_option_values.product_option_id', '=', 'product_options.id')
                            ->addSelect('product_options.option_name');
                    }
                ])
                    ->select('id', 'product_id', 'sku', 'price', 'stock')
                    ->orderBy('price', 'asc');
            },
            'addonGroups' => function ($q) {
                $q->with([
                    'options' => function ($oq) {
                        $oq->with('addon:id,addon_name')
                            ->select('id', 'addon_group_id', 'addon_id', 'addon_price');
                    }
                ])
                    ->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection')
                    ->orderBy('id');
            },
        ]);

        // 1. Tentukan apakah produk ini Public atau Draft
        $isPublic = in_array($product->status, ['published', 'archived']);

        // 2. Manipulasi collection 'images' untuk menambahkan field 'url_siap_pakai'
        if ($product->images) {
            $product->images->transform(function ($image) use ($isPublic) {
                // Jika Public -> URL biasa
                // Jika Draft -> Signed URL (Berlaku 60 menit)
                $image->src_url = $isPublic
                    ? route('images.show', ['image' => $image->id])
                    : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));

                return $image;
            });
        }

        // 3. Lakukan hal yang sama untuk coverImage (jika ada)
        if ($product->coverImage) {
            $product->coverImage->src_url = $isPublic
                ? route('images.show', ['image' => $product->coverImage->id])
                : URL::signedRoute('images.show', ['image' => $product->coverImage->id], now()->addMinutes(60));
        }

        // ============================================================

        return response()->json($product);
    }

    /**
     * ✅ UPDATED: Full product update with images, variants, addons
     * Use slug instead of id
     */
    public function update(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error) return $error;

        // Validasi (Sama seperti sebelumnya)
        $data = $request->validate([
            // Basic Info
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug,' . $product->id],
            'description' => ['sometimes', 'required', 'string'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'sub_categories' => ['nullable', 'array', 'max:4'],
            'sub_categories.*' => ['integer', 'exists:categories,id'],
            'min_purchase' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:draft,published,archived'],

            // Images
            'images' => ['nullable', 'array', 'max:6'],
            'images.*.file' => ['required', 'file', 'image', 'max:5120'],
            'images.*.order' => ['required', 'integer', 'min:0'],
            'existing_images' => ['nullable', 'array'],
            'existing_images.*.id' => ['required', 'integer'],
            'existing_images.*.order' => ['required', 'integer', 'min:0'],
            'existing_images.*.is_cover' => ['required', 'boolean'],
            'cover_image_index' => ['nullable', 'integer'],

            // Single Product Info
            'sku' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // Variants (Options)
            'variants' => ['nullable', 'array', 'max:' . self::MAX_VARIANTS],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:100'],
            'variants.*.uses_images' => ['required', 'in:0,1'],
            'variants.*.options' => ['required', 'array', 'min:1'],
            'variants.*.options.*.id' => ['nullable', 'integer'],
            'variants.*.options.*.name' => ['required', 'string', 'max:100'],
            'variants.*.options.*.images' => ['nullable', 'array'],
            'variants.*.options.*.images.*.file' => ['file', 'image', 'max:5120'],

            // Combinations (SKUs)
            'combinations' => ['nullable', 'array', 'max:' . self::MAX_VARIANT_COMBINATIONS],
            'combinations.*.sku' => ['nullable', 'string', 'max:100'],
            'combinations.*.price' => ['required', 'numeric', 'min:0'],
            'combinations.*.stock' => ['required', 'integer', 'min:0', 'max:9999'],
            'combinations.*.attributes' => ['required', 'array'],

            // Addons
            'add_on_groups' => ['nullable', 'array', 'max:' . self::MAX_ADDON_GROUPS],
            'add_on_groups.*.id' => ['nullable', 'integer'],
            'add_on_groups.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.min_selection' => ['required', 'integer', 'min:0'],
            'add_on_groups.*.max_selection' => ['required', 'integer', 'min:1'],
            'add_on_groups.*.options' => ['required', 'array', 'min:1', 'max:' . (self::MAX_ADDON_GROUP_OPTIONS)],
            'add_on_groups.*.options.*.id' => ['nullable', 'integer'],
            'add_on_groups.*.options.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.options.*.price' => ['required', 'numeric', 'min:0'],
        ]);
        $existingCount = isset($data['existing_images'])
            ? count($data['existing_images'])
            : 0;

        $newCount = isset($data['images'])
            ? count($data['images'])
            : 0;

        $totalImages = $existingCount + $newCount;

        if ($totalImages > 6) {
            return response()->json([
                'message' => "Maksimal upload 6 foto produk. Saat ini {$totalImages} foto dipilih.",
            ], 422);
        }

        if (!empty($data['variants'])) {
            $variantNames = collect($data['variants'])
                ->pluck('name')
                ->map(fn($name) => strtolower(trim($name)));

            if ($variantNames->count() !== $variantNames->unique()->count()) {
                return response()->json([
                    'message' => 'Nama varian tidak boleh sama.',
                ], 422);
            }
        }
        // Validasi: jika tidak pakai variants, harus ada price & stock
        $useVariants = !empty($data['variants']);
        if (!$useVariants && (!isset($data['price']) || !isset($data['stock']))) {
            return response()->json([
                'message' => 'Harga dan stok wajib diisi jika tidak menggunakan variasi.',
            ], 422);
        }

        // Validasi: jika pakai variants, harus ada combinations
        if ($useVariants && empty($data['combinations'])) {
            return response()->json([
                'message' => 'Kombinasi variasi wajib diisi jika menggunakan variasi.',
            ], 422);
        }
        // Validasi: combinations tidak boleh duplikat
        if ($useVariants) {
            $combinations = collect($data['combinations']);
            $uniqueCombinations = $combinations->pluck('combination')->unique();

            if ($combinations->count() !== $uniqueCombinations->count()) {
                return response()->json([
                    'message' => 'Kombinasi variasi tidak boleh duplikat.',
                ], 422);
            }
        }
        if (!empty($data['add_on_groups'])) {
            $groupNames = collect($data['add_on_groups'])
                ->mapWithKeys(function ($group) {
                    return [
                        strtolower(trim($group['name'])) => $group['id'] ?? null
                    ];
                });

            if ($groupNames->keys()->count() !== $groupNames->keys()->unique()->count()) {
                return response()->json([
                    'message' => 'Nama grup add-on tidak boleh sama.',
                ], 422);
            }
        }

        if (!empty($data['add_on_groups'])) {
            foreach ($data['add_on_groups'] as $group) {
                if (!empty($group['options'])) {
                    $optionNames = collect($group['options'])
                        ->mapWithKeys(function ($opt) {
                            return [
                                strtolower(trim($opt['name'])) => $opt['id'] ?? null
                            ];
                        });

                    if ($optionNames->keys()->count() !== $optionNames->keys()->unique()->count()) {
                        return response()->json([
                            'message' => "Nama opsi add-on pada grup '{$group['name']}' tidak boleh sama.",
                        ], 422);
                    }
                }
            }
        }
        DB::beginTransaction();
        try {
            // 1. UPDATE BASIC INFO
            $newName = $data['name'] ?? $product->name;
            $updateData = [
                'name' => $newName,
                'description' => $data['description'] ?? $product->description,
                'min_purchase' => $data['min_purchase'] ?? $product->min_purchase,
            ];

            if (!empty($data['slug'])) {
                $updateData['slug'] = $data['slug'];
            } elseif ($newName !== $product->name) {
                $updateData['slug'] = $this->generateUniqueSlugForUpdate($newName, $product->id);
            }

            if (isset($data['status'])) {
                $updateData['status'] = $data['status'];
            }

            $product->update($updateData);

            // 2. UPDATE CATEGORIES
            if (isset($data['category_id'])) {
                $categoryIds = [$data['category_id']];
                if (!empty($data['sub_categories'])) {
                    $categoryIds = array_merge($categoryIds, $data['sub_categories']);
                }
                $product->categories()->sync(array_unique($categoryIds));
            }


            // 3. UPDATE IMAGES
            $this->updateProductImages($product, $data);

            // 4. UPDATE VARIANTS OR DIRECT PRICING
            $useVariants = isset($data['variants']) && is_array($data['variants']) && count($data['variants']) > 0;

            if ($useVariants) {
                // Hapus data single variant jika ada (agar tidak bentrok)
                $product->variants()->whereNull('product_id')->delete(); // Sesuaikan query jika perlu

                // Update Logic Variant Kompleks
                $this->updateProductVariants($product, $data);
            } else {
                // Mode Produk Tanpa Varian (Single SKU)
                // Hapus semua opsi/varian lama jika sebelumnya produk ini punya varian
                $product->options()->delete();
                $product->variants()->delete();

                // Create/Update Single Variant
                $product->variants()->create([
                    'sku' => $data['sku'] ?? null,
                    'price' => $data['price'] ?? 0,
                    'stock' => $data['stock'] ?? 0,
                    'is_active' => 1
                ]);
            }

            // 5. UPDATE ADDON GROUPS
            if (isset($data['add_on_groups'])) {
                $this->updateAddonGroups($product, $data['add_on_groups']);
            } else {
                // Jika user menghapus semua addon
                $product->addonGroups()->delete();
            }

            DB::commit();

            return response()->json($product->fresh()->load([
                'images',
                'categories',
                'options.values',
                'variants',
                'addonGroups.options.addon'
            ]));
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    /**
     * HELPER: Update product images
     */
    private function updateProductVariants(Product $product, array $data): void
    {
        $submittedOptionIds = [];

        // 1. UPDATE / CREATE OPTIONS (Warna, Ukuran)
        foreach ($data['variants'] as $vIndex => $variantData) {
            $option = null;

            // Cek apakah ID valid dan ada di database
            if (!empty($variantData['id'])) {
                $option = $product->options()->find($variantData['id']);
            }

            $usesImages = ($vIndex === 0) && ((int) $variantData['uses_images'] === 1);
            $payload = [
                'option_name' => $variantData['name'],
                'uses_image' => $usesImages,
                'order' => $vIndex
            ];

            if ($option) {
                $option->update($payload);
            } else {
                $option = $product->options()->create($payload);
            }

            $submittedOptionIds[] = $option->id;

            // 1b. UPDATE / CREATE OPTION VALUES (Merah, Biru / S, M)
            $submittedValueIds = [];
            $valuesMap = []; // Untuk mapping nama value ke ID (dipakai kombinasi nanti)

            foreach ($variantData['options'] as $valIndex => $valData) {
                $value = null;

                if (!empty($valData['id'])) {
                    $value = $option->values()->find($valData['id']);
                }

                if ($value) {
                    $value->update(['option_value' => $valData['name']]);
                } else {
                    $value = $option->values()->create(['option_value' => $valData['name']]);
                }

                $submittedValueIds[] = $value->id;

                // Simpan mapping untuk kombinasi: "Warna:Merah" => ID 5
                // Kita simpan mapping global berdasarkan nama opsi dan nama value
                $valuesMap[$valData['name']] = $value->id;

                // Handle Image Upload untuk Option Value (misal foto warna Merah)
                if ($usesImages && !empty($valData['images'])) {
                    // Cek file upload baru
                    if (isset($valData['images'][0]['file'])) {
                        // Hapus gambar lama jika ada
                        if ($value->image_path) {
                            Storage::disk('public')->delete($value->image_path);
                        }
                        $path = $valData['images'][0]['file']->store('option-values', 'public');
                        $value->update(['image_path' => $path]);
                    }
                }
            }

            // Hapus value yang tidak ada di request
            $option->values()->whereNotIn('id', $submittedValueIds)->delete();

            // Simpan referensi map ke variant data agar mudah diakses loop kombinasi
            $data['variants'][$vIndex]['_value_map'] = $valuesMap;
        }

        // Hapus Option yang tidak ada di request
        $product->options()->whereNotIn('id', $submittedOptionIds)->delete();


        // 2. REGENERATE COMBINATIONS (SKU Real)
        // Cara paling aman saat update struktur varian adalah menghapus semua SKU kombinasi lama
        // dan membuat ulang berdasarkan data kombinasi baru dari frontend.

        $product->variants()->delete(); // Hapus SKU lama

        if (!empty($data['combinations'])) {
            foreach ($data['combinations'] as $combo) {
                $variant = $product->variants()->create([
                    'sku' => $combo['sku'] ?? null,
                    'price' => $combo['price'],
                    'stock' => $combo['stock'],
                    'is_active' => 1
                ]);

                // Attach ke tabel pivot product_option_value_product_variant
                // Kita perlu mencari ID dari option_values berdasarkan nama atribut
                $optionValueIdsToAttach = [];

                foreach ($combo['attributes'] as $attr) {
                    $attrName = $attr['name']; // Misal "Warna"
                    $attrValue = $attr['value']; // Misal "Merah"

                    // Cari ID option value yang cocok dari proses update opsi di atas
                    // Kita loop data variants yang sudah kita proses map-nya
                    foreach ($data['variants'] as $vProcessed) {
                        if ($vProcessed['name'] === $attrName && isset($vProcessed['_value_map'][$attrValue])) {
                            $optionValueIdsToAttach[] = $vProcessed['_value_map'][$attrValue];
                            break;
                        }
                    }
                }

                if (!empty($optionValueIdsToAttach)) {
                    $variant->optionValues()->attach($optionValueIdsToAttach);
                }
            }
        }
    }

    /**
     * LOGIKA UPDATE ADDONS YANG AMAN
     */
    private function updateAddonGroups(Product $product, array $groups): void
    {
        $submittedGroupIds = [];

        // ==============================
        // VALIDASI DUPLIKAT GROUP NAME (DB LEVEL)
        // ==============================
        $groupNameMap = [];

        foreach ($groups as $groupData) {
            $key = strtolower(trim($groupData['name']));

            if (isset($groupNameMap[$key])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'add_on_groups' => 'Nama grup add-on tidak boleh sama.',
                ]);
            }

            $groupNameMap[$key] = true;
        }

        foreach ($groups as $groupData) {
            $addonGroup = null;

            if (!empty($groupData['id'])) {
                $addonGroup = $product->addonGroups()->find($groupData['id']);
            }

            $selectionType = ($groupData['max_selection'] === 1) ? 'single' : 'multiple';

            $payload = [
                'addon_group_name' => trim($groupData['name']),
                'selection_type' => $selectionType,
                'min_selection' => $groupData['min_selection'],
                'max_selection' => $groupData['max_selection'],
            ];

            if ($addonGroup) {
                $addonGroup->update($payload);
            } else {
                $addonGroup = $product->addonGroups()->create($payload);
            }

            $submittedGroupIds[] = $addonGroup->id;

            // ==============================
            // VALIDASI DUPLIKAT OPTION NAME (PER GROUP)
            // ==============================
            $optionNameMap = [];
            $submittedOptionIds = [];

            foreach ($groupData['options'] as $optionData) {
                $optKey = strtolower(trim($optionData['name']));

                if (isset($optionNameMap[$optKey])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'add_on_groups' =>
                        "Nama opsi '{$optionData['name']}' pada grup '{$groupData['name']}' tidak boleh sama.",
                    ]);
                }

                $optionNameMap[$optKey] = true;

                $addonMaster = $product->merchant->addons()->firstOrCreate(
                    ['addon_name' => trim($optionData['name'])],
                    ['addon_name' => trim($optionData['name'])]
                );

                $groupOption = null;
                if (!empty($optionData['id'])) {
                    $groupOption = $addonGroup->options()->find($optionData['id']);
                }

                $optPayload = [
                    'addon_id' => $addonMaster->id,
                    'addon_price' => $optionData['price'],
                ];

                if ($groupOption) {
                    $groupOption->update($optPayload);
                } else {
                    $groupOption = $addonGroup->options()->create($optPayload);
                }

                $submittedOptionIds[] = $groupOption->id;
            }

            // Hapus opsi yang dihapus user
            $addonGroup->options()
                ->whereNotIn('id', $submittedOptionIds)
                ->delete();
        }

        // Hapus grup yang dihapus user
        $product->addonGroups()
            ->whereNotIn('id', $submittedGroupIds)
            ->delete();
    }


    private function generateUniqueSlugForUpdate(string $name, int $currentProductId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $count = 1;

        while (
            Product::where('slug', $slug)
            ->where('id', '!=', $currentProductId)
            ->exists()
        ) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * ✅ HELPER: Update product images
     */
    private function updateProductImages(Product $product, array $data): void
    {
        $existingCount = isset($data['existing_images']) ? count($data['existing_images']) : 0;
        $newCount = isset($data['images']) ? count($data['images']) : 0;

        if (($existingCount + $newCount) > 6) {
            throw new \RuntimeException('Maksimal upload 6 foto produk.');
        }

        $hasExisting = !empty($data['existing_images']);
        $hasNew = !empty($data['images']);

        if (!$hasExisting && !$hasNew)
            return;

        $entries = [];

        // Existing images
        if ($hasExisting) {
            foreach ($data['existing_images'] as $img) {
                $entries[] = [
                    'type' => 'existing',
                    'id' => (int) $img['id'],
                    'order' => (int) $img['order'],
                ];
            }
        }

        // New images
        if ($hasNew) {
            foreach ($data['images'] as $img) {
                $entries[] = [
                    'type' => 'new',
                    'file' => $img['file'],
                    'order' => (int) $img['order'],
                ];
            }
        }

        // Sort berdasarkan order
        usort($entries, fn($a, $b) => $a['order'] <=> $b['order']);

        // ================= DELETE =================
        if ($hasExisting) {
            $keepIds = collect($entries)
                ->where('type', 'existing')
                ->pluck('id')
                ->all();

            Image::where('imageable_type', 'product')
                ->where('imageable_id', $product->id)
                ->whereNotIn('id', $keepIds)
                ->each(function ($img) {
                    $this->deleteImageFileIfExists($img->image_path);
                    $img->delete();
                });
        } elseif ($hasNew) {
            // Semua gambar lama dihapus jika user upload baru tanpa existing_images
            Image::where('imageable_type', 'product')
                ->where('imageable_id', $product->id)
                ->each(function ($img) {
                    $this->deleteImageFileIfExists($img->image_path);
                    $img->delete();
                });
        }

        // Reset semua cover
        $product->images()->update(['is_cover' => false]);

        // ================= INSERT / UPDATE =================
        $displayOrder = 0;

        foreach ($entries as $entry) {
            $isCover = $displayOrder === 0; // 🔥 RULE FINAL

            if ($entry['type'] === 'existing') {
                Image::where('id', $entry['id'])->update([
                    'display_order' => $displayOrder,
                    'is_cover' => $isCover,
                ]);
            } else {
                $path = $entry['file']->store("products/{$product->id}", 'public');

                $product->images()->create([
                    'image_path' => $path,
                    'display_order' => $displayOrder,
                    'is_cover' => $isCover,
                ]);
            }

            $displayOrder++;
        }
    }



    /**
     * ✅ HELPER: Update product variants
     */


    public function updateStatus(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'status' => ['required', 'in:published,archived'],
        ]);

        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error) {
            return $error;
        }

        try {
            $product->update(['status' => $data['status']]);

            return response()->json([
                'message' => 'Status produk berhasil diperbarui.',
                'product' => $product,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memperbarui status produk.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Delete product (also deletes images files)
    // Use slug instead of id
    public function destroy(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        foreach ($product->images as $img) {
            $this->deleteImageFileIfExists($img->image_path);
        }
        $product->delete();

        return response()->json(['message' => 'Produk dihapus']);
    }

    // Add images to product
    // Use slug instead of id
    public function storeImage(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $data = $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['file', 'image', 'max:5120'],
        ]);

        $created = $this->storeUploadedImages($product, $data['images'], 0);

        return response()->json(['images' => $created, 'cover' => $product->fresh()->coverImage], 201);
    }

    /**
     * Get total possible combinations
     * Use slug instead of id
     */
    public function getCombinationCount(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $options = $product->options()->with('values')->get();

        if ($options->isEmpty()) {
            return response()->json(['total_combinations' => 0]);
        }

        $totalCombinations = $options->reduce(function ($carry, $option) {
            return $carry * $option->values->count();
        }, 1);

        return response()->json([
            'total_combinations' => $totalCombinations,
            'max_allowed' => 50,
            'exceeds_limit' => $totalCombinations > 50,
        ]);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    private function findOwnedMerchantOrAbort(int $userId, int $merchantId): Merchant|array
    {
        try {
            $merchant = Merchant::query()->findOrFail($merchantId);
        } catch (ModelNotFoundException $e) {
            return ['error' => response()->json(['message' => 'UMKM tidak ditemukan.'], 404)];
        }

        if ((int) ($merchant->user_id ?? 0) !== $userId) {
            return ['error' => response()->json(['message' => 'UMKM tidak sah. Anda bukan pemilik UMKM ini.'], 403)];
        }

        // ✅ WAJIB approved
        if ($merchant->status !== 'approved') {
            return ['error' => response()->json(['message' => 'UMKM belum disetujui oleh admin.'], 403)];
        }

        $segmentId = $merchant->segmentation_id
            ?? $merchant->segment_id
            ?? optional($merchant->segmentation)->id
            ?? null;

        if (!in_array((int) $segmentId, self::ALLOWED_SEGMENT_IDS, true)) {
            return ['error' => response()->json(['message' => 'Segment UMKM tidak diizinkan untuk mengelola produk.'], 403)];
        }

        return $merchant;
    }

    private function abortIfNotOwnerOrNotAllowed(int $userId, int $merchantId)
    {
        $result = $this->findOwnedMerchantOrAbort($userId, $merchantId);

        if (is_array($result) && isset($result['error'])) {
            return $result['error'];
        }

        return null;
    }

    private function storeUploadedImages(Product $product, array $files, ?int $coverIndex = null): array
    {
        $disk = config('filesystems.product_disk', 'public'); // ✅ Ubah ke 'public' jika perlu
        $now = now();
        $savedPaths = [];
        $rows = [];

        $imageableType = $product->getMorphClass();

        try {
            $manager = new ImageIntervention(new Driver());

            foreach ($files as $file) {
                $uniq = uniqid('', true);
                $path = "products/{$product->id}/{$uniq}.webp";

                // ✅ PERBAIKAN: Gunakan Intervention Image v3
                $img = $manager->read($file->getRealPath()); // v3 pakai read()

                // Resize ke medium max width 1200px
                $img->scale(width: 1200); // v3 pakai scale()

                // Encode ke WebP
                $encoded = $img->toWebp(quality: 85);

                // Save ke storage
                Storage::disk($disk)->put($path, (string) $encoded);
                $savedPaths[] = $path;

                $displayOrder = (int) ($product->images()->max('display_order') ?? -1) + 1;

                $rows[] = [
                    'imageable_type' => $imageableType,
                    'imageable_id' => $product->id,
                    'image_path' => $path,
                    'display_order' => $displayOrder,
                    'is_cover' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // batch insert
            DB::table('images')->insert($rows);

            // fetch created
            $created = $product->images()->where('created_at', '>=', $now)->orderBy('id')->get();

            // set cover
            if ($coverIndex !== null && isset($created[$coverIndex])) {
                $this->reorderAfterSetCover($product, $created[$coverIndex]);
            }

            return $created->toArray();
        } catch (\Throwable $e) {
            // cleanup
            foreach ($savedPaths as $p) {
                if (Storage::disk($disk)->exists($p)) {
                    Storage::disk($disk)->delete($p);
                }
            }
            Log::error("Upload gagal: " . $e->getMessage());
            throw $e;
        }
    }



    /**
     * Set image sebagai cover dan pindahkan display_order ke 0, geser sisanya +1
     */
    private function reorderAfterSetCover(Product $product, Image $newCover): void
    {
        // Reset cover lama
        $product->images()->where('is_cover', true)->update(['is_cover' => false]);

        // Geser semua image (kecuali newCover) yang display_order >= 0
        $product->images()
            ->where('id', '!=', $newCover->id)
            ->where('display_order', '>=', 0)
            ->increment('display_order');

        // Set newCover ke display_order 0 dan is_cover true
        $newCover->update([
            'display_order' => 0,
            'is_cover' => true,
        ]);
    }

    private function deleteImageFileIfExists(?string $path): void
    {
        if (!$path)
            return;
        $disk = config('filesystems.product_disk', 'public');

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function buildFilteredProductQuery(int $merchantId, array $data)
    {
        $query = Product::query()
            ->where('merchant_id', $merchantId)
            ->with([
                'coverImage',
                'images',
                'categories:id,name,slug',
            ])
            ->withCount('variants');

        // add select computed columns
        $query->addSelect([
            'products.*',
            'total_stock' => function ($q) {
                $q->selectRaw('COALESCE(SUM(stock), 0)')
                    ->from('product_variants')
                    ->whereColumn('product_id', 'products.id');
            },
            'min_price' => function ($q) {
                $q->selectRaw('COALESCE(MIN(price), 0)')
                    ->from('product_variants')
                    ->whereColumn('product_id', 'products.id');
            },
            'max_price' => function ($q) {
                $q->selectRaw('COALESCE(MAX(price), 0)')
                    ->from('product_variants')
                    ->whereColumn('product_id', 'products.id');
            },
            'sku' => function ($q) {
                $q->select('sku')
                    ->from('product_variants')
                    ->whereColumn('product_id', 'products.id')
                    ->orderBy('id')
                    ->limit(1);
            },
        ]);

        // search q
        if (!empty($data['q'])) {
            $searchTerm = trim($data['q']);
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('slug', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%')
                    ->orWhereHas('variants', function ($vq) use ($searchTerm) {
                        $vq->where('sku', 'like', '%' . $searchTerm . '%');
                    });
            });
        }

        // status
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        // category
        if (!empty($data['category_id'])) {
            $query->whereHas('categories', function ($q) use ($data) {
                $q->where('categories.id', $data['category_id']);
            });
        }

        // price range filter on variants
        if (isset($data['min_price']) || isset($data['max_price'])) {
            $minPrice = $data['min_price'] ?? 0;
            $maxPrice = $data['max_price'] ?? PHP_INT_MAX;

            $query->whereHas('variants', function ($q) use ($minPrice, $maxPrice) {
                $q->whereBetween('price', [$minPrice, $maxPrice]);
            });
        }

        // stock range using subquery grouping
        if (isset($data['min_stock']) || isset($data['max_stock'])) {
            $minStock = $data['min_stock'] ?? 0;
            $maxStock = $data['max_stock'] ?? PHP_INT_MAX;

            $query->whereIn('id', function ($subquery) use ($minStock, $maxStock) {
                $subquery->select('product_id')
                    ->from('product_variants')
                    ->groupBy('product_id')
                    ->havingRaw('SUM(stock) BETWEEN ? AND ?', [$minStock, $maxStock]);
            });
        }

        // sorting
        switch ($data['sort_by'] ?? 'newest') {
            case 'oldest':
                $query->orderBy('products.created_at', 'asc');
                break;
            case 'name_asc':
                $query->orderBy('products.name', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('products.name', 'desc');
                break;
            case 'price_asc':
                $query->orderByRaw('COALESCE((SELECT MIN(price) FROM product_variants WHERE product_id = products.id), 0) ASC');
                break;
            case 'price_desc':
                $query->orderByRaw('COALESCE((SELECT MAX(price) FROM product_variants WHERE product_id = products.id), 0) DESC');
                break;
            case 'stock_asc':
                $query->orderByRaw('COALESCE((SELECT SUM(stock) FROM product_variants WHERE product_id = products.id), 0) ASC');
                break;
            case 'stock_desc':
                $query->orderByRaw('COALESCE((SELECT SUM(stock) FROM product_variants WHERE product_id = products.id), 0) DESC');
                break;
            case 'newest':
            default:
                $query->orderBy('products.created_at', 'desc');
                break;
        }

        return $query;
    }

    private function buildFilteredVariantQuery(int $merchantId, array $data)
    {
        $productsTable = (new Product)->getTable();

        $variants = ProductVariant::query()
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.price',
                'product_variants.stock',
            ])
            ->with([
                'product' => function ($p) {
                    $p->select('id', 'merchant_id', 'name', 'status', 'created_at');
                },
                'product.categories:id,name',
                'optionValues.option:id,option_name',
            ])
            ->whereHas('product', fn($p) => $p->where('merchant_id', $merchantId))
            ->join($productsTable, $productsTable . '.id', '=', 'product_variants.product_id')
            ->addSelect('product_variants.*');

        // Apply same filters as product list but on variants join
        if (!empty($data['q'])) {
            $term = trim($data['q']);
            $variants->where(function ($w) use ($term, $productsTable) {
                $w->where('product_variants.sku', 'like', "%{$term}%")
                    ->orWhere('product_variants.name', 'like', "%{$term}%")
                    ->orWhere($productsTable . '.name', 'like', "%{$term}%")
                    ->orWhere($productsTable . '.slug', 'like', "%{$term}%")
                    ->orWhere($productsTable . '.description', 'like', "%{$term}%");
            });
        }

        if (!empty($data['status'])) {
            $variants->where($productsTable . '.status', $data['status']);
        }

        if (!empty($data['category_id'])) {
            $variants->whereHas('product.categories', fn($wc) => $wc->where('categories.id', $data['category_id']));
        }

        if (isset($data['min_price']) || isset($data['max_price'])) {
            $min = $data['min_price'] ?? 0;
            $max = $data['max_price'] ?? PHP_INT_MAX;
            $variants->whereBetween('product_variants.price', [$min, $max]);
        }

        if (isset($data['min_stock']) || isset($data['max_stock'])) {
            $minS = $data['min_stock'] ?? 0;
            $maxS = $data['max_stock'] ?? PHP_INT_MAX;
            $variants->whereBetween('product_variants.stock', [$minS, $maxS]);
        }

        // Sorting group
        switch ($data['sort_by'] ?? 'newest') {
            case 'oldest':
                $variants->orderBy($productsTable . '.created_at', 'asc');
                break;
            case 'name_asc':
                $variants->orderBy($productsTable . '.name', 'asc');
                break;
            case 'name_desc':
                $variants->orderBy($productsTable . '.name', 'desc');
                break;
            case 'price_asc':
                $variants->orderByRaw('COALESCE((SELECT MIN(price) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) ASC');
                break;
            case 'price_desc':
                $variants->orderByRaw('COALESCE((SELECT MAX(price) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) DESC');
                break;
            case 'stock_asc':
                $variants->orderByRaw('COALESCE((SELECT SUM(stock) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) ASC');
                break;
            case 'stock_desc':
                $variants->orderByRaw('COALESCE((SELECT SUM(stock) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) DESC');
                break;
            case 'newest':
            default:
                $variants->orderBy($productsTable . '.created_at', 'desc');
                break;
        }

        // Default secondary ordering to stabilize output
        $variants->orderBy('product_variants.price', 'asc')->orderBy('product_variants.id', 'asc');

        return $variants;
    }

    public function exportExcel(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0', 'gte:min_stock'],
            'sort_by' => ['nullable', 'in:newest,oldest,name_asc,name_desc,price_asc,price_desc,stock_asc,stock_desc'],
        ]);

        // merchant detection same as index...
        if (empty($data['merchant_id'])) {
            $merchant = Merchant::where('user_id', $request->user()->id)
                ->where('status', 'approved')
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();
            if (!$merchant) {
                return response()->json(['message' => 'Anda belum memiliki UMKM.'], 403);
            }
            $merchantId = $merchant->id;
        } else {
            $owned = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
            if (is_array($owned) && isset($owned['error']))
                return $owned['error'];
            $merchantId = $owned->id;
        }

        // Build same query and get full collection (no pagination for export)
        $query = $this->buildFilteredProductQuery($merchantId, $data);

        // Eager load any relations as needed and get results
        $products = $query->get();

        $fileName = 'products-' . now()->format('Ymd-His') . '.xlsx';

        // ProductsExport expects merchantId (int), not collection
        return Excel::download(new ProductsExport($merchantId, $data), $fileName);
    }


    public function exportPdf(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0', 'gte:min_stock'],
            'sort_by' => ['nullable', 'in:newest,oldest,name_asc,name_desc,price_asc,price_desc,stock_asc,stock_desc'],
        ]);

        // merchant detection same as index...
        if (empty($data['merchant_id'])) {
            $merchant = Merchant::where('user_id', $request->user()->id)
                ->where('status', 'approved')
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();
            if (!$merchant) {
                return response()->json(['message' => 'Anda belum memiliki UMKM.'], 403);
            }
            $merchantId = $merchant->id;
        } else {
            $owned = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
            if (is_array($owned) && isset($owned['error']))
                return $owned['error'];
            $merchantId = $owned->id;
        }

        // Use helper to build variant query with same filters
        $variantsQuery = $this->buildFilteredVariantQuery($merchantId, $data);



        $rows = $variantsQuery->get();

        return Pdf::loadView('exports.products', ['variants' => $rows])
            ->setPaper('a4', 'landscape')
            ->download('products-' . now()->format('Ymd-His') . '.pdf');
    }

    /**
     * Bulk delete products
     * DELETE /api/products/bulk-delete
     */
    public function bulkDelete(Request $request)
    {
        $data = $request->validate([
            'product_slugs' => ['required', 'array', 'min:1'],
            'product_slugs.*' => ['required', 'string', 'exists:products,slug'],
        ]);

        $user = $request->user();
        $slugs = $data['product_slugs'];

        // Get products and validate ownership
        $products = Product::whereIn('slug', $slugs)->get();

        $unauthorizedCount = 0;
        $deletedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($products as $product) {
                // Check ownership
                $error = $this->abortIfNotOwnerOrNotAllowed($user->id, $product->merchant_id);
                if ($error) {
                    $unauthorizedCount++;
                    continue;
                }

                // Delete product files
                $this->cleanupProductFiles($product);

                // Delete product record
                $product->delete();
                $deletedCount++;
            }

            DB::commit();

            return response()->json([
                'message' => "Berhasil menghapus {$deletedCount} produk",
                'deleted_count' => $deletedCount,
                'unauthorized_count' => $unauthorizedCount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk delete products failed', [
                'error' => $e->getMessage(),
                'slugs' => $slugs,
            ]);

            return response()->json([
                'message' => 'Gagal menghapus produk',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk update product status
     * POST /api/products/bulk-update-status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $data = $request->validate([
            'product_slugs' => ['required', 'array', 'min:1'],
            'product_slugs.*' => ['required', 'string', 'exists:products,slug'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        $user = $request->user();
        $slugs = $data['product_slugs'];
        $newStatus = $data['status'];

        // Get products and validate ownership
        $products = Product::whereIn('slug', $slugs)->get();

        $unauthorizedCount = 0;
        $updatedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($products as $product) {
                // Check ownership
                $error = $this->abortIfNotOwnerOrNotAllowed($user->id, $product->merchant_id);
                if ($error) {
                    $unauthorizedCount++;
                    continue;
                }

                // Update status
                $product->update(['status' => $newStatus]);
                $updatedCount++;
            }

            DB::commit();

            return response()->json([
                'message' => "Berhasil mengubah status {$updatedCount} produk menjadi {$newStatus}",
                'updated_count' => $updatedCount,
                'unauthorized_count' => $unauthorizedCount,
                'new_status' => $newStatus,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk update status failed', [
                'error' => $e->getMessage(),
                'slugs' => $slugs,
                'status' => $newStatus,
            ]);

            return response()->json([
                'message' => 'Gagal mengubah status produk',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
