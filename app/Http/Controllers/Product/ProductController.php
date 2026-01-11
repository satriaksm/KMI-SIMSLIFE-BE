<?php

namespace App\Http\Controllers\Product;

use App\Models\Image;
use App\Models\Product;
use App\Models\CartItem;
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
use Illuminate\Validation\ValidationException;
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
            'segments' => ['nullable', 'array'],
            'segments.*' => ['in:UMKM Toko,UMKM Kuliner,UMKM Jasa'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc,name_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()
            ->select([
                'products.id',
                'products.merchant_id',
                'products.name',
                'products.slug',
            ])
            ->whereHas('variants', function ($q) {
                $q->where('stock', '>', 0);
            })->where('products.status', 'published')
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with([
                'coverImage:id,imageable_id,imageable_type,image_path',
                'merchant:id,name,slug,segmentation_id',
                'merchant.segmentation:id,name',
                'categories:id,name',
                'variants:id,product_id,price',
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

        if (!empty($data['segments'])) {
            $query->whereHas('merchant.segmentation', function ($q) use ($data) {
                $q->whereIn('name', $data['segments']);
            });
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

        return response()->json(
            $query->paginate($perPage)->through(function ($product) {

                $cover = $product->coverImage
                    ? route('images.show', ['image' => $product->coverImage->id])
                    : null;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'min_price' => $product->variants->min('price'),
                    'max_price' => $product->variants->max('price'),
                    'slug' => $product->slug,

                    'cover_image' => $product->coverImage ? [
                        'id' => $product->coverImage->id,
                        'src_url' => $cover,
                    ] : null,

                    'merchant' => [
                        'id' => $product->merchant->id,
                        'name' => $product->merchant->name,
                        'slug' => $product->merchant->slug,
                        'segmentation' => [
                            'name' => $product->merchant->segmentation?->name,
                        ],
                    ],

                    'categories' => $product->categories->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                    ])->values(),
                ];
            })
        );
    }


    /**
     * Public: Get product detail by slug (PDP - Product Detail Page)
     * No authentication required
     */
    public function publicShow(string $slug)
    {
        // 1. QUERY PRODUCT
        $product = Product::select([
            'id',
            'merchant_id',
            'name',
            'description',
            'status',
            'min_purchase',
        ])
            ->where('slug', $slug)
            ->whereIn('status', ['published', 'archived']) // Public usually only allows these
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with([
                // Images
                'coverImage' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type', 'image_path'),
                'images' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type', 'image_path')->orderBy('display_order'),

                // Merchant & categories
                'merchant.addresses.district.city.province',
                'categories:id,name,slug',

                // Options
                'options' => function ($q) {
                    $q->select('id', 'product_id', 'option_name', 'uses_image')
                        ->orderBy('id')
                        ->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')]);
                },

                // Variants
                'variants' => function ($q) {
                    $q->select('id', 'product_id', 'stock', 'price', 'sku')
                        ->orderBy('price', 'asc')
                        ->with([
                            'optionValues' => function ($ovq) {
                                $ovq->select('product_option_values.id', 'product_option_values.product_option_id', 'product_option_values.option_value')
                                    ->join('product_options', 'product_option_values.product_option_id', '=', 'product_options.id')
                                    ->addSelect('product_options.option_name');
                            }
                        ]);
                },

                // Addon groups
                'addonGroups' => function ($q) {
                    $q->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection')
                        ->orderBy('id')
                        ->with([
                            'options' => function ($oq) {
                                $oq->select('id', 'addon_group_id', 'addon_id', 'addon_price')
                                    ->with('addon:id,addon_name');
                            }
                        ]);
                },
            ])
            ->firstOrFail();

        // ============================================================
        // 2. TRANSFORMASI DATA & URL GENERATION
        // ============================================================

        // Karena ini publicShow, logikanya status pasti published/archived.
        // Tapi kita tetap pakai pengecekan in_array untuk konsistensi.
        $isPublic = in_array($product->status, ['published', 'archived']);

        // A. Handle Cover Image
        if ($product->coverImage) {
            $product->coverImage->src_url = $isPublic
                ? route('images.show', ['image' => $product->coverImage->id])
                : URL::signedRoute('images.show', ['image' => $product->coverImage->id], now()->addMinutes(60));

            $product->coverImage->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
        }

        // B. Handle Gallery Images
        if ($product->images) {
            $product->images->transform(function ($image) use ($isPublic) {
                $image->src_url = $isPublic
                    ? route('images.show', ['image' => $image->id])
                    : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));

                $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                return $image;
            });
        }

        // C. Handle Option Values Images
        if ($product->options) {
            $product->options->transform(function ($option) use ($isPublic) {
                if ($option->values) {
                    $option->values->transform(function ($value) use ($isPublic) {
                        if (!empty($value->image_path)) {
                            $value->src_url = $isPublic
                                ? route('images.product-option-value.show', ['optionValue' => $value->id])
                                : URL::signedRoute('images.product-option-value.show', ['optionValue' => $value->id], now()->addMinutes(60));
                        } else {
                            $value->src_url = null;
                        }

                        $value->makeHidden(['image_path', 'created_at', 'updated_at', 'image_url']); // hide accessor image_url if exists
                        return $value;
                    });
                }
                $option->makeHidden(['created_at', 'updated_at']);
                return $option;
            });
        }

        // D. Clean Categories Pivot
        if ($product->categories) {
            $product->categories->transform(function ($cat) {
                $cat->makeHidden(['pivot', 'created_at', 'updated_at']);
                return $cat;
            });
        }

        // E. Clean Variants Pivot & Accessor
        if ($product->variants) {
            $product->variants->transform(function ($variant) {
                $variant->makeHidden(['display_image', 'created_at', 'updated_at']); // Hide display_image accessor

                if ($variant->optionValues) {
                    $variant->optionValues->transform(function ($ov) {
                        $ov->makeHidden(['pivot', 'image_url', 'created_at', 'updated_at']);
                        return $ov;
                    });
                }
                return $variant;
            });
        }

        // ============================================================
        // 3. LOGIC LAINNYA (Address, Related, Price Range)
        // ============================================================

        // Range harga dari variants
        $variants = $product->variants;
        $priceRange = [
            'min' => $variants->min('price'),
            'max' => $variants->max('price'),
        ];

        // Opsi 1 dan 2
        $option1 = optional($product->options)->get(0);
        $option2 = optional($product->options)->get(1);

        // Kombinasi harga & stok
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

        // ✅ Format Alamat Merchant dan tambahkan ke merchant object
        if ($product->merchant && $product->merchant->addresses) {
            $address = $product->merchant->addresses->first();
            if ($address) {
                $parts = array_filter([
                    $address->detail,
                    $address->district?->name,
                    $address->city?->name,
                    $address->province?->name
                ]);
                $product->merchant->address = implode(', ', $parts);
            } else {
                $product->merchant->address = null;
            }
        } else {
            if ($product->merchant) {
                $product->merchant->address = null;
            }
        }

        // ✅ Ambil 5 produk lain dari merchant yang sama, acak, exclude produk ini
        $relatedProducts = Product::where('merchant_id', $product->merchant_id)
            ->where('id', '!=', $product->id)
            ->where('status', 'published')

            // 🔥 FILTER PENTING: HARUS ADA STOK
            ->whereHas('variants', function ($q) {
                $q->where('stock', '>', 0);
            })

            ->with([
                'merchant:id,name,slug',
                'coverImage' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type', 'image_path'),
                'variants:id,product_id,price,stock',
            ])

            ->inRandomOrder()
            ->limit(5)
            ->get()

            ->map(function ($p) {

                $coverUrl = $p->coverImage
                    ? route('images.show', ['image' => $p->coverImage->id])
                    : null;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,

                    // 🔥 harga hanya dari variant yang ada stok
                    'min_price' => $p->variants->where('stock', '>', 0)->min('price'),
                    'max_price' => $p->variants->where('stock', '>', 0)->max('price'),

                    'merchant' => [
                        'id' => $p->merchant->id,
                        'name' => $p->merchant->name,
                        'slug' => $p->merchant->slug,
                    ],

                    'cover_image' => $p->coverImage ? [
                        'id' => $p->coverImage->id,
                        'src_url' => $coverUrl,
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'product' => $product,
            'price_range' => $priceRange,
            'total_stock' => $variants->sum('stock'),
            'has_variants' => $variants->isNotEmpty(),
            'has_addons' => $product->addonGroups->isNotEmpty(),
            'combinations' => $combinations,
            'option_labels' => [
                'option1' => $option1 ? $option1->option_name : null,
                'option2' => $option2 ? $option2->option_name : null,
            ],
            'min_purchase' => $minPurchase,
            'related_products' => $relatedProducts,
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
        ]);

        if (empty($data['merchant_id'])) {
            $merchant = Merchant::where('user_id', $request->user()->id)
                ->where('status', 'approved')
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();

            if (!$merchant)
                return response()->json(['message' => 'Anda belum memiliki UMKM.'], 403);
            $merchantId = $merchant->id;
        } else {
            $merchantOrError = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
            if (is_array($merchantOrError) && isset($merchantOrError['error']))
                return $merchantOrError['error'];
            $merchantId = $merchantOrError->id;
        }

        // 1. BUILD QUERY (Filter)
        $query = $this->buildFilteredProductQuery($merchantId, $data);

        // [OPTIMASI] Jangan load images dari Model (global scope)
        $query->without('images');

        // 2. OPTIMASI SELECT
        // Pilih kolom tabel products
        $query->select([
            'products.id',
            'products.merchant_id',
            'products.name',
            'products.status',
            'products.min_purchase',
            'products.slug',
            'sku' => ProductVariant::select('sku')
                ->whereColumn('product_id', 'products.id')
                ->orderBy('id')
                ->limit(1),
        ]);

        // 3. TAMBAHKAN COMPUTED COLUMNS (Total Stock, Min Price, Max Price)
        // Menggunakan Subquery agar efisien (hanya 1 query utama)
        $query->addSelect([
            'total_stock' => ProductVariant::selectRaw('COALESCE(SUM(stock), 0)')
                ->whereColumn('product_id', 'products.id'),

            'min_price' => ProductVariant::selectRaw('COALESCE(MIN(price), 0)')
                ->whereColumn('product_id', 'products.id'),

            'max_price' => ProductVariant::selectRaw('COALESCE(MAX(price), 0)')
                ->whereColumn('product_id', 'products.id'),
        ]);

        // 4. EAGER LOAD RELASI (Cover & Categories)
        $query->with([
            'coverImage' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type', 'image_path'),
            'categories' => fn($q) => $q->select('categories.id', 'categories.name'),
        ]);

        $perPage = $data['per_page'] ?? 10;
        $result = $query->paginate($perPage);

        // 5. TRANSFORMASI DATA
        $result->getCollection()->transform(function ($product) {

            $isPublic = in_array($product->status, ['published', 'archived']);

            // A. Handle Cover Image
            if ($product->coverImage) {
                $product->coverImage->src_url = $isPublic
                    ? route('images.show', ['image' => $product->coverImage->id])
                    : URL::signedRoute('images.show', ['image' => $product->coverImage->id], now()->addMinutes(60));

                $product->coverImage->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
            }

            // B. Handle Categories
            if ($product->categories) {
                $product->categories->transform(function ($cat) {
                    $cat->makeHidden(['pivot', 'created_at', 'updated_at']);
                    return $cat;
                });
            }

            // C. Bersihkan object product
            // Kita sembunyikan 'images' agar tidak muncul di JSON
            $product->makeHidden(['images', 'created_at', 'updated_at', 'description']);

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

    public function store(Request $request)
    {
        $data = $request->validate([
            // Basic product info
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'min_purchase' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,published,archived'],

            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100'],
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
        $useVariants =
            array_key_exists('variants', $data) &&
            collect($data['variants'] ?? [])
                ->filter(function ($variant) {
                    if (empty(trim($variant['name'] ?? ''))) {
                        return false;
                    }

                    if (empty($variant['options']) || !is_array($variant['options'])) {
                        return false;
                    }

                    $validOptions = collect($variant['options'])
                        ->filter(fn($opt) => !empty(trim($opt['name'] ?? '')))
                        ->count();

                    return $validOptions >= 1;
                })
                ->count() > 0;
        if (
            !$useVariants &&
            (
                !array_key_exists('price', $data) ||
                !array_key_exists('stock', $data) ||
                $data['price'] === null ||
                $data['stock'] === null
            )
        ) {
            return response()->json([
                'message' => 'Harga dan stok wajib diisi jika tidak menggunakan variasi.',
            ], 422);
        }

        if ($useVariants) {
            $hasVariantWithAtLeastTwoOptions = collect($data['variants'])
                ->some(function ($variant) {
                    if (empty($variant['options']) || !is_array($variant['options'])) {
                        return false;
                    }

                    // hitung opsi yang valid (nama terisi)
                    $validOptionsCount = collect($variant['options'])
                        ->filter(fn($opt) => !empty(trim($opt['name'] ?? '')))
                        ->count();

                    return $validOptionsCount >= 2;
                });

            if (!$hasVariantWithAtLeastTwoOptions) {
                return response()->json([
                    'message' => 'Jika menggunakan variasi, minimal salah satu varian harus memiliki 2 pilihan atau lebih.',
                ], 422);
            }
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
        // 1. QUERY UTAMA: Pilih kolom tabel 'products' saja
        // WAJIB: 'id' (untuk relasi), 'merchant_id' (untuk cek permission), 'status' (untuk logic signed url)
        $product = Product::select([
            'id',
            'merchant_id',
            'slug',
            'name',
            'description',
            'status',
            'min_purchase',
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        // 2. CEK PERMISSION
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        // 3. EAGER LOAD DENGAN SELECT
        $product->load([
            // Select kolom tabel images
            'coverImage' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type'),
            'images' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type')
                ->orderBy('display_order'),

            // Select kolom tabel categories + pivot
            'categories' => fn($q) => $q->select('categories.id', 'categories.name'),

            'options' => function ($q) {
                $q->select('id', 'product_id', 'option_name', 'uses_image') // product_id WAJIB agar nyambung ke products
                    ->orderBy('id')
                    ->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')]); // product_option_id WAJIB
            },

            'variants' => function ($q) {
                $q->select('id', 'product_id', 'sku', 'price', 'stock') // product_id WAJIB
                    ->orderBy('price', 'asc')
                    ->with([
                        'optionValues' => function ($ovq) {
                            // Join diperlukan jika ingin mengambil nama option parent-nya juga
                            $ovq->select('product_option_values.id', 'product_option_values.product_option_id', 'product_option_values.option_value')
                                ->join('product_options', 'product_option_values.product_option_id', '=', 'product_options.id')
                                ->addSelect('product_options.option_name');
                        }
                    ]);
            },

            'addonGroups' => function ($q) {
                $q->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection') // product_id WAJIB
                    ->orderBy('id')
                    ->with([
                        'options' => function ($oq) {
                            $oq->select('id', 'addon_group_id', 'addon_id', 'addon_price') // addon_group_id WAJIB
                                ->with('addon:id,addon_name'); // addon_id WAJIB
                        }
                    ]);
            },
        ]);

        // ============================================================
        // LOGIC SIGNED URL (SAMA SEPERTI SEBELUMNYA)
        // ============================================================

        $isPublic = in_array($product->status, ['published', 'archived']);
        // A. BERSIHKAN CATEGORIES (Hapus pivot)
        if ($product->categories) {
            $product->categories->transform(function ($category) {
                $category->makeHidden(['pivot', 'created_at', 'updated_at']);
                return $category;
            });
        }

        // B. BERSIHKAN VARIANTS (Hapus display_image & pivot)
        if ($product->variants) {
            $product->variants->transform(function ($variant) {
                // Hapus display_image dari variant
                $variant->makeHidden(['display_image', 'created_at', 'updated_at']);

                // Bersihkan option_values di dalam variant
                if ($variant->optionValues) {
                    $variant->optionValues->transform(function ($ov) {
                        // Hapus pivot object dan image_url bawaan (jika ada accessor)
                        $ov->makeHidden(['pivot', 'image_url', 'created_at', 'updated_at']);
                        return $ov;
                    });
                }
                return $variant;
            });
        }
        if ($product->images) {
            $product->images->transform(function ($image) use ($isPublic) {
                $image->src_url = $isPublic
                    ? route('images.show', ['image' => $image->id])
                    : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));
                return $image;
            });
        }

        if ($product->coverImage) {
            $product->coverImage->src_url = $isPublic
                ? route('images.show', ['image' => $product->coverImage->id])
                : URL::signedRoute('images.show', ['image' => $product->coverImage->id], now()->addMinutes(60));
        }

        if ($product->options) {
            $product->options->transform(function ($option) use ($isPublic) {
                if ($option->values) {
                    $option->values->transform(function ($value) use ($isPublic) {
                        if (!empty($value->image_path)) {
                            $value->src_url = $isPublic
                                ? route('images.product-option-value.show', ['optionValue' => $value->id])
                                : URL::signedRoute('images.product-option-value.show', ['optionValue' => $value->id], now()->addMinutes(60));
                        } else {
                            $value->src_url = null;
                        }
                        return $value;
                    });
                }
                return $option;
            });
        }

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
        if ($error)
            return $error;

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
            'combinations.*.id' => ['nullable', 'integer', 'exists:product_variants,id'],
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
        if (
            !$useVariants &&
            (
                !array_key_exists('price', $data) ||
                !array_key_exists('stock', $data)
            )
        ) {
            return response()->json([
                'message' => 'Harga dan stok wajib diisi jika tidak menggunakan variasi.',
            ], 422);
        }
        if ($useVariants) {
            $hasVariantWithAtLeastTwoOptions = collect($data['variants'])
                ->some(function ($variant) {
                    if (empty($variant['options']) || !is_array($variant['options'])) {
                        return false;
                    }

                    // hitung opsi yang valid (nama terisi)
                    $validOptionsCount = collect($variant['options'])
                        ->filter(fn($opt) => !empty(trim($opt['name'] ?? '')))
                        ->count();

                    return $validOptionsCount >= 2;
                });

            if (!$hasVariantWithAtLeastTwoOptions) {
                return response()->json([
                    'message' => 'Jika menggunakan variasi, minimal salah satu varian harus memiliki 2 pilihan atau lebih.',
                ], 422);
            }
        }
        // Validasi: jika pakai variants, harus ada combinations
        if ($useVariants && empty($data['combinations'])) {
            return response()->json([
                'message' => 'Kombinasi variasi wajib diisi jika menggunakan variasi.',
            ], 422);
        }
        // Validasi: combinations tidak boleh duplikat
// ✅ VALIDASI: kombinasi tidak boleh duplikat (BERDASARKAN name + value)
        if ($useVariants) {
            $uniqueCombinations = collect($data['combinations'])
                ->map(function ($combo) {
                    return collect($combo['attributes'])
                        ->map(
                            fn($a) =>
                            strtolower(trim($a['name'])) . ':' . strtolower(trim($a['value']))
                        )
                        ->sort()
                        ->implode('|');
                })
                ->unique();

            if (count($data['combinations']) !== $uniqueCombinations->count()) {
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
                $product->variants()->each(function ($variant) {
                    $variant->optionValues()->detach();
                    $variant->delete();
                });
                // Update Logic Variant Kompleks
                $this->updateProductVariants($product, $data);
            } else {
                // ==============================
                // MODE TANPA VARIAN (SINGLE SKU)
                // ==============================

                // 1️⃣ Hapus SEMUA relasi varian lama
                $product->variants()->each(function ($variant) {
                    $variant->optionValues()->detach(); // pivot
                    $variant->delete();
                });

                // 2️⃣ Hapus semua product options
                $product->options()->delete();

                // 3️⃣ Buat 1 single variant baru
                $product->variants()->create([
                    'sku' => $data['sku'] ?? null,
                    'price' => $data['price'],
                    'stock' => $data['stock'],
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
        $incomingOptions = collect($data['variants']);
        $existingOptions = $product->options()->with('values')->get()->keyBy('id');

        $optionValueMap = []; // [optionIndex][valueName] => valueId
        $keptOptionIds = [];

        /**
         * ==================================================
         * 1️⃣ UPSERT OPTIONS & OPTION VALUES
         * ==================================================
         */
        foreach ($incomingOptions as $vIndex => $variantData) {

            // ---------- OPTION ----------
            if (!empty($variantData['id']) && $existingOptions->has($variantData['id'])) {
                $option = $existingOptions[$variantData['id']];
                $option->update([
                    'option_name' => $variantData['name'],
                    'uses_image' => $variantData['uses_images'],
                ]);
            } else {
                $option = $product->options()->create([
                    'option_name' => $variantData['name'],
                    'uses_image' => $variantData['uses_images'],
                ]);
            }

            $keptOptionIds[] = $option->id;

            // ---------- OPTION VALUES ----------
            $existingValues = $option->values->keyBy('id');
            $keptValueIds = [];

            foreach ($variantData['options'] as $opt) {

                // upload image jika ada
                $imagePath = null;
                if (!empty($opt['images'][0]['file'])) {
                    $imagePath = $opt['images'][0]['file']
                        ->store("product-options/{$product->id}", 'public');
                }

                if (!empty($opt['id']) && $existingValues->has($opt['id'])) {
                    // UPDATE
                    $value = $existingValues[$opt['id']];
                    $value->update([
                        'option_value' => $opt['name'],
                        'image_path' => $imagePath ?? $value->image_path,
                    ]);
                } else {
                    // CREATE
                    $value = $option->values()->create([
                        'option_value' => $opt['name'],
                        'image_path' => $imagePath,
                    ]);
                }

                $keptValueIds[] = $value->id;

                // map utk combinations
                $optionValueMap[$vIndex][strtolower(trim($opt['name']))] = $value->id;
            }

            // DELETE VALUE yang dihapus user
            $option->values()
                ->whereNotIn('id', $keptValueIds)
                ->each(function ($val) {
                    if ($val->image_path) {
                        Storage::disk('public')->delete($val->image_path);
                    }
                    $val->delete();
                });
        }

        // DELETE OPTION yang dihapus user
        $product->options()
            ->whereNotIn('id', $keptOptionIds)
            ->each(function ($opt) {
                foreach ($opt->values as $val) {
                    if ($val->image_path) {
                        Storage::disk('public')->delete($val->image_path);
                    }
                }
                $opt->delete();
            });

        /**
         * ==================================================
         * 2️⃣ UPSERT PRODUCT VARIANTS (COMBINATIONS)
         * ==================================================
         */
        $existingVariants = $product->variants()->get()->keyBy('id');
        $keptVariantIds = [];

        foreach ($data['combinations'] as $combo) {

            $optionValueIds = [];

            foreach ($combo['attributes'] as $aIndex => $attr) {
                $key = strtolower(trim($attr['value']));
                if (isset($optionValueMap[$aIndex][$key])) {
                    $optionValueIds[] = $optionValueMap[$aIndex][$key];
                }
            }

            if (!empty($combo['id']) && $existingVariants->has($combo['id'])) {
                $variant = $existingVariants[$combo['id']];
                $variant->update([
                    'sku' => $combo['sku'] ?? null,
                    'price' => $combo['price'],
                    'stock' => $combo['stock'],
                ]);
            } else {
                $variant = $product->variants()->create([
                    'sku' => $combo['sku'] ?? null,
                    'price' => $combo['price'],
                    'stock' => $combo['stock'],
                ]);
            }

            $variant->optionValues()->sync($optionValueIds);
            $keptVariantIds[] = $variant->id;
        }

        // DELETE VARIANT yang dihapus user
        $product->variants()
            ->whereNotIn('id', $keptVariantIds)
            ->each(function ($variant) {
                $variant->optionValues()->detach();
                $variant->delete();
            });
    }


    /**
     * LOGIKA UPDATE ADDONS YANG AMAN
     */
    private function updateAddonGroups(Product $product, array $groups): void
    {
        $submittedGroupIds = [];

        /**
         * =================================================
         * 1️⃣ VALIDASI DUPLIKAT NAMA GROUP (CASE INSENSITIVE)
         * =================================================
         */
        $groupNameMap = [];

        foreach ($groups as $groupData) {
            $groupName = strtolower(trim($groupData['name']));

            if (isset($groupNameMap[$groupName])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'add_on_groups' => 'Nama grup add-on tidak boleh sama.',
                ]);
            }

            $groupNameMap[$groupName] = true;
        }

        /**
         * =================================================
         * 2️⃣ CREATE / UPDATE GROUP
         * =================================================
         */
        foreach ($groups as $groupData) {
            $addonGroup = null;

            // 🔐 TERIMA ID HANYA JIKA INTEGER (ID DB)
            if (isset($groupData['id']) && is_int($groupData['id'])) {
                $addonGroup = $product->addonGroups()->find($groupData['id']);
            }

            $selectionType = ((int) $groupData['max_selection'] === 1)
                ? 'single'
                : 'multiple';

            $groupPayload = [
                'addon_group_name' => trim($groupData['name']),
                'selection_type' => $selectionType,
                'min_selection' => (int) $groupData['min_selection'],
                'max_selection' => (int) $groupData['max_selection'],
            ];

            if ($addonGroup) {
                $addonGroup->update($groupPayload);
            } else {
                $addonGroup = $product->addonGroups()->create($groupPayload);
            }

            $submittedGroupIds[] = $addonGroup->id;

            /**
             * =================================================
             * 3️⃣ CREATE / UPDATE OPTIONS (PER GROUP)
             * =================================================
             */
            $submittedOptionIds = [];
            $optionNameMap = [];

            foreach ($groupData['options'] as $optionData) {
                $optionName = strtolower(trim($optionData['name']));

                // ❌ DUPLIKAT NAMA OPTION
                if (isset($optionNameMap[$optionName])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'add_on_groups' =>
                            "Nama opsi '{$optionData['name']}' pada grup '{$groupData['name']}' tidak boleh sama.",
                    ]);
                }

                $optionNameMap[$optionName] = true;

                // 🔁 MASTER ADDON (GLOBAL PER MERCHANT)
                $addonMaster = $product->merchant->addons()->firstOrCreate(
                    ['addon_name' => trim($optionData['name'])],
                    ['addon_name' => trim($optionData['name'])]
                );

                $groupOption = null;

                // 🔐 TERIMA ID OPTION HANYA JIKA INTEGER (ID DB)
                if (isset($optionData['id']) && is_int($optionData['id'])) {
                    $groupOption = $addonGroup->options()->find($optionData['id']);
                }

                $optionPayload = [
                    'addon_id' => $addonMaster->id,
                    'addon_price' => (float) $optionData['price'],
                ];

                if ($groupOption) {
                    $groupOption->update($optionPayload);
                } else {
                    $groupOption = $addonGroup->options()->create($optionPayload);
                }

                $submittedOptionIds[] = $groupOption->id;
            }

            /**
             * =================================================
             * 4️⃣ DELETE OPTION YANG DIHAPUS USER
             * =================================================
             */
            $addonGroup->options()
                ->whereNotIn('id', $submittedOptionIds)
                ->delete();
        }

        /**
         * =================================================
         * 5️⃣ DELETE GROUP YANG DIHAPUS USER
         * =================================================
         */
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
            ->where('merchant_id', $merchantId);
        // ->with([
        //     'coverImage',
        //     'images',
        //     'categories:id,name,slug',
        // ])
        // ->withCount('variants');

        // add select computed columns
        $query->addSelect([
            // 'products.*',
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
