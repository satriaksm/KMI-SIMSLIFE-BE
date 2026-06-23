<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\Addon;
use App\Models\AddonGroup;
use App\Models\AddonGroupOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\Merchant;
use App\Models\Rating;
use App\Models\RatingSummary;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Exports\ProductsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Helpers\ApiResponse;
use App\Services\Moderation\ContentModerationService;

class ProductController extends Controller
{
    private const MAX_VARIANT_COMBINATIONS = 50;

    public function __construct(
        private readonly ContentModerationService $moderationService
    ) {
    }

    private function productPublishBlockedResponse(Product $product)
    {
        $meta = $this->moderationService->productModerationBlockMeta($product) ?? [];

        return ApiResponse::error(
            $this->moderationService->productPublishBlockedMessage(),
            403,
            null,
            array_merge($meta, [
                'product_name' => $product->name,
                'product_slug' => $product->slug,
            ]),
            'moderation_blocked'
        );
    }
    private const MAX_VARIANTS = 2;
    private const MAX_ADDON_GROUPS = 10;
    private const MAX_ADDON_GROUP_OPTIONS = 10;

    // ============================================================
    // PUBLIC ENDPOINTS (No Auth Required)
    // ============================================================

    /**
     * Public: Get product detail by slug (PDP - Product Detail Page)
     * No authentication required
     */
    public function publicShow(Product $product)
    {
        // 1. QUERY PRODUCT
        $product = Product::select([
            'id',
            'merchant_id',
            'status',
            'slug',
            'name',
            'description',
            'min_purchase',
        ])
            // Public endpoint: only published products should be visible
            ->where('status', 'published')
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with([
                // Merchant (for checkout store address)
                'merchant.primaryAddress' => function ($q) {
                    $q->select([
                        'id',
                        'addressable_id',
                        'addressable_type',
                        'province_id',
                        'city_id',
                        'district_id',
                        'village_id',
                        'detail',
                        'label',
                        'latitude',
                        'longitude',
                    ])->with([
                                'province:id,name',
                                'city:id,name',
                                'district:id,name',
                                'village:id,name',
                            ]);
                },

                // Images
                // 'coverImage' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type', 'image_path'),
                'images' => fn($q) => $q->select('id', 'imageable_id', 'imageable_type', 'image_path')->orderBy('display_order'),

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

                // Rating summary
                'ratingSummary',

                // Ratings with user and media
                'ratings.user',
                'ratings.media',
            ])
            ->findOrFail($product->id);

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

        // ============================================================
        // 4. RESPONSE SHAPE (attach computed fields into product)
        // ============================================================

        // Normalize min_purchase to int for FE
        $product->min_purchase = $minPurchase;

        $product->setAttribute('price_range', $priceRange);
        $product->setAttribute('total_stock', (int) $variants->sum('stock'));
        $product->setAttribute('has_variants', $variants->isNotEmpty());
        $product->setAttribute('has_addons', $product->addonGroups->isNotEmpty());
        $product->setAttribute('combinations', $combinations);
        $product->setAttribute('option_labels', [
            'option1' => $option1 ? $option1->option_name : null,
            'option2' => $option2 ? $option2->option_name : null,
        ]);

        // ✅ Public product payload: merchant relation intentionally excluded
        $product->makeHidden(['merchant']);

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

        $merchantPrimaryAddress = $product->merchant?->primaryAddress;
        $merchantAddress = $merchantPrimaryAddress?->full_address;

        // Calculate rating summary for product
        $ratingSummary = $product->ratingSummary;
        $ratings = $product->ratings ?? collect();
        $productRatingSummary = [
            'average_rating' => $ratingSummary?->average_rating
                ?? ($ratings->isNotEmpty() ? round($ratings->avg('rating'), 1) : 0),
            'total_reviews' => $ratingSummary?->total_reviews ?? $ratings->count(),
            'rating_5_count' => $ratingSummary?->rating_5_count ?? $ratings->where('rating', 5)->count(),
            'rating_4_count' => $ratingSummary?->rating_4_count ?? $ratings->where('rating', 4)->count(),
            'rating_3_count' => $ratingSummary?->rating_3_count ?? $ratings->where('rating', 3)->count(),
            'rating_2_count' => $ratingSummary?->rating_2_count ?? $ratings->where('rating', 2)->count(),
            'rating_1_count' => $ratingSummary?->rating_1_count ?? $ratings->where('rating', 1)->count(),
        ];

        // Normalize ratings list
        $productRatings = $ratings->map(function ($r) {
            return [
                'id' => $r->id,
                'rating' => $r->rating,
                'title' => $r->title,
                'comment' => $r->comment,
                'created_at' => $r->created_at,
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                ] : null,
                'media' => $r->media->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'file_type' => $m->file_type,
                        'file_path' => $m->file_path,
                        'file_url' => $m->file_url,
                        'media_url' => $m->media_url,
                    ];
                }),
            ];
        })->values();

        return ApiResponse::success(
            [
                'product' => $product,
                'rating_summary' => $productRatingSummary,
                'ratings' => $productRatings,
                'merchant' => [
                    'id' => $product->merchant->id,
                    'name' => $product->merchant->name,
                    'slug' => $product->merchant->slug,
                    'is_open_now' => $product->merchant->is_open_now,
                    'logo_url' => $product->merchant->logo_url,
                    // Used by FE checkout-from-product-detail
                    'address' => $merchantAddress,
                    'primary_address' => $merchantPrimaryAddress ? [
                        'id' => $merchantPrimaryAddress->id,
                        'label' => $merchantPrimaryAddress->label,
                        'detail' => $merchantPrimaryAddress->detail,
                        'full_address' => $merchantAddress,
                        'latitude' => $merchantPrimaryAddress->latitude,
                        'longitude' => $merchantPrimaryAddress->longitude,
                    ] : null,
                ],
                'related_products' => $relatedProducts,
            ],
            'success',
            200
        );
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
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()
            ->select([
                'products.id',
                'products.merchant_id',
                'products.name',
                'products.slug',
                'products.created_at',
            ])
            ->where('merchant_id', $merchant->id)
            ->whereIn('status', ['published'])
            ->whereHas('variants', function ($q) {
                $q->where('stock', '>', 0);
            })
            ->with([

                'coverImage:id,imageable_id,imageable_type,image_path',
            ]);

        if (!empty($data['q'])) {
            $query->where('name', 'like', '%' . $data['q'] . '%');
        }

        // Match SearchController::searchProducts output (min/max price fields)
        $query->addSelect([
            'min_price' => ProductVariant::selectRaw('MIN(price)')
                ->whereColumn('product_id', 'products.id'),

            'max_price' => ProductVariant::selectRaw('MAX(price)')
                ->whereColumn('product_id', 'products.id'),
        ]);

        switch ($data['sort'] ?? 'newest') {
            case 'price_asc':
                $query->orderBy('min_price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('max_price', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('products.name', 'asc');
                break;
            case 'newest':
            default:
                $query->orderByDesc('products.created_at');
                break;
        }

        $perPage = $data['per_page'] ?? 20;

        $result = $query->paginate($perPage);
        $items = collect($result->items())
            ->map(function ($product) {
                if ($product->coverImage) {
                    $product->cover_image = route('images.show', ['image' => $product->coverImage->id]);
                } else {
                    $product->cover_image = null;
                }

                unset($product->coverImage);

                // Add rating_summary for each product
                $product->rating_summary = RatingSummary::getProductRatingSummary($product->id);

                return $product;
            })
            ->values();

        return ApiResponse::success(
            $items,
            'success',
            200,
            [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'total' => $result->total(),
            ]
        );
    }


    // ============================================================
    // PROTECTED ENDPOINTS (Auth Required - Merchant Owner)
    // ============================================================

    /**
     * ✅ UPDATED: List products by merchant (auto-detect merchant dari user)
     */
    public function index(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
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

        $this->authorize('manageProduct', $merchant);


        $merchantId = $merchant->id;

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

            // C. Moderation block info (for archived-by-admin products)
            $product->moderation_block = $this->moderationService->productModerationBlockMeta($product);

            // D. Bersihkan object product
            // Kita sembunyikan 'images' agar tidak muncul di JSON
            $product->makeHidden(['images', 'created_at', 'updated_at', 'description']);

            return $product;
        });
        return ApiResponse::success(
            $result->items(),
            'success',
            200,
            [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
            ]
        );
    }

    public function store(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            // Basic product info
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'min_purchase' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,published,archived'],

            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:9999'],
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
            'combinations.*.attributes.*.option_value_id' => ['nullable', 'integer', 'exists:product_option_values,id'],
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
        ], [
            'stock.max' => 'Stok produk tidak boleh melebihi 9999.',
            'combinations.*.stock.max' => 'Stok variasi tidak boleh melebihi 9999.',
        ]);

        $images = $data['images'] ?? [];
        $coverIndex = $data['cover_image_index'] ?? 0;

        if (count($images) > 6) {
            return response()->json([
                'message' => 'Maksimal upload 6 foto produk.',
            ], 422);
        }

        if (empty($data['images'])) {
            return response()->json([
                'message' => 'Minimal 1 foto produk diperlukan.',
            ], 422);
        }

        $this->authorize('manageProduct', $merchant);

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
        // Prefer option_value_id signature (stable), fallback to name:value
        if ($useVariants) {
            $uniqueCombinations = collect($data['combinations'])
                ->map(function ($combo) {
                    return collect($combo['attributes'] ?? [])
                        ->map(function ($a) {
                            if (!empty($a['option_value_id'])) {
                                return 'id:' . (int) $a['option_value_id'];
                            }
                            $n = strtolower(trim((string) ($a['name'] ?? '')));
                            $v = strtolower(trim((string) ($a['value'] ?? '')));
                            return 'nv:' . $n . ':' . $v;
                        })
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
            $this->storeProductImages($product, $images, is_int($coverIndex) ? $coverIndex : 0);

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
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($product)) {
                $this->cleanupProductFiles($product);
            }
            throw $e;
        }

        // Return minimal data — FE redirects immediately after creation and doesn't use the full payload.
        // Returning only what's needed for the optimistic update keeps DB connections free.
        return ApiResponse::success([
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'status' => $product->status,
        ], 'Produk berhasil dibuat', 201);
    }

    /**
     * HELPER: Upload product images
     */
    private function storeProductImages(Product $product, array $images, int $coverIndex = 0): void
    {
        if (empty($images)) {
            return;
        }

        $imagesToInsert = [];
        $now = now();

        // Sort by order
        usort($images, fn($a, $b) => $a['order'] <=> $b['order']);

        if ($coverIndex < 0 || $coverIndex >= count($images)) {
            $coverIndex = 0;
        }

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

        if (!empty($imagesToInsert)) {
            DB::table('images')->insert($imagesToInsert);
        }
    }

    /**
     * ============================================================
     * ADMIN ENDPOINTS (Auth Required - Admin)
     * ============================================================
     */
    public function adminIndex(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()->select([
            'id',
            'merchant_id',
            'name',
            'slug',
            'status',
            'min_purchase',
            'created_at',
        ])->with([
                    'merchant:id,name,slug',
                ]);

        if (!empty($data['q'])) {
            $query->where('name', 'like', '%' . $data['q'] . '%');
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $perPage = $data['per_page'] ?? 10;
        $result = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::success(
            $result->items(),
            'success',
            200,
            [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
            ]
        );
    }

    public function adminDestroy(int $id)
    {
        $product = Product::query()->with(['images', 'addonGroups.options', 'merchant'])->findOrFail($id);

        $merchantId = (int) ($product->merchant_id ?? 0);

        // Collect addon ids used by this product before deleting (FK cascades will remove group/options)
        $addonIds = $this->getAddonIdsForProduct($product);

        $this->cleanupProductFiles($product);

        $product->delete();

        if ($merchantId > 0) {
            $this->cleanupOrphanAddons($addonIds, $merchantId);
        }

        return ApiResponse::success(null, 'Produk dihapus.', 200);
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
                throw ValidationException::withMessages([
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
                    throw ValidationException::withMessages([
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
     * ✅ FIXED: Get product (owner only) - WITH ADDONS COMPLETE
     * Use slug instead of id
     */
    public function show(Request $request, Merchant $merchant, Product $product)
    {
        // 1) Merchant-level permission (owner/approved/segment)
        $this->authorize('manageProduct', $merchant);

        if ((int) $product->merchant_id !== (int) $merchant->id) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        // 2) Product-level permission (delegates to MerchantPolicy via ProductPolicy::manage)
        $this->authorize('manage', $product);

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
            ->whereKey($product->id)
            ->firstOrFail();

        // EAGER LOAD DENGAN SELECT
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

        return ApiResponse::success($product, 'success', 200);

    }

    /**
     * ✅ UPDATED: Full product update with images, variants, addons
     * Use slug instead of id
     */
    public function update(Request $request, Merchant $merchant, Product $product)
    {
        // 1) Merchant-level permission (owner/approved/segment)
        $this->authorize('manageProduct', $merchant);

        if ((int) $product->merchant_id !== (int) $merchant->id) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        // 2) Product-level permission
        $this->authorize('manage', $product);

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
            'combinations.*.attributes.*.option_value_id' => ['nullable', 'integer', 'exists:product_option_values,id'],
            'combinations.*.attributes.*.name' => ['required', 'string'],
            'combinations.*.attributes.*.value' => ['required', 'string'],

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
        ], [
            'stock.max' => 'Stok produk tidak boleh melebihi 9999.',
            'combinations.*.stock.max' => 'Stok variasi tidak boleh melebihi 9999.',
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
        // Prefer option_value_id signature (stable), fallback to name:value
        if ($useVariants) {
            $uniqueCombinations = collect($data['combinations'])
                ->map(function ($combo) {
                    return collect($combo['attributes'] ?? [])
                        ->map(function ($a) {
                            if (!empty($a['option_value_id'])) {
                                return 'id:' . (int) $a['option_value_id'];
                            }
                            $n = strtolower(trim((string) ($a['name'] ?? '')));
                            $v = strtolower(trim((string) ($a['value'] ?? '')));
                            return 'nv:' . $n . ':' . $v;
                        })
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
                if ($data['status'] === 'published' && $this->moderationService->hasActiveProductSanction($product)) {
                    return $this->productPublishBlockedResponse($product);
                }
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
                // Update Logic Variant Kompleks
                $this->updateProductVariants($product, $data);
            } else {
                // ==============================
                // MODE TANPA VARIAN (SINGLE SKU)
                // ==============================

                // 1) Pastikan hanya ada 1 variant, dan usahakan reuse variant existing
                $existingVariants = $product->variants()->orderBy('id')->get();
                $keptVariant = $existingVariants->first();

                if ($keptVariant) {
                    // Single-SKU variant should not have optionValues
                    $keptVariant->optionValues()->detach();

                    // Delete extra variants (if any)
                    $existingVariants->slice(1)->each(function (ProductVariant $variant) {
                        $variant->optionValues()->detach();
                        $variant->delete();
                    });

                    // Update existing variant instead of creating a new one (keeps id)
                    $keptVariant->update([
                        'sku' => $data['sku'] ?? null,
                        'price' => $data['price'],
                        'stock' => $data['stock'],
                    ]);
                } else {
                    $product->variants()->create([
                        'sku' => $data['sku'] ?? null,
                        'price' => $data['price'],
                        'stock' => $data['stock'],
                    ]);
                }

                // 2) Hapus semua product options (karena mode tanpa varian)
                $product->options()->delete();
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
        $optionNameToIndex = []; // [lower(option_name)] => optionIndex
        $keptOptionIds = [];
        $allKeptValueIds = [];

        /**
         * ==================================================
         * 1️⃣ UPSERT OPTIONS & OPTION VALUES
         * ==================================================
         */
        foreach ($incomingOptions as $vIndex => $variantData) {
            $optionNameToIndex[strtolower(trim($variantData['name']))] = $vIndex;

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
                $allKeptValueIds[] = $value->id;

                // map utk combinations
                $optionValueMap[$vIndex][strtolower(trim($opt['name']))] = $value->id;
            }

            // DELETE VALUE yang dihapus user
            $option->values()
                ->whereNotIn('id', $keptValueIds)
                ->each(function (ProductOptionValue $val) {
                    if ($val->image_path) {
                        Storage::disk('public')->delete($val->image_path);
                    }
                    $val->delete();
                });
        }

        // DELETE OPTION yang dihapus user
        $product->options()
            ->whereNotIn('id', $keptOptionIds)
            ->each(function (ProductOption $opt) {
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
        // Build lookup of existing variants by their optionValues combination key
        $existingVariants = $product->variants()->with('optionValues:id')->get();
        $existingVariantsById = $existingVariants->keyBy('id');
        $existingVariantsByComboKey = [];
        foreach ($existingVariants as $existingVariant) {
            $ids = $existingVariant->optionValues
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $key = implode('-', $ids);
            $existingVariantsByComboKey[$key] = $existingVariant;
        }

        $keptVariantIds = [];
        $seenIncomingComboKeys = [];

        foreach ($data['combinations'] as $combo) {
            $optionValueIds = [];

            foreach ($combo['attributes'] as $attr) {
                // Prefer stable IDs from FE payload
                if (!empty($attr['option_value_id'])) {
                    $optionValueIds[] = (int) $attr['option_value_id'];
                    continue;
                }

                // Fallback for older payloads: resolve by option name + value
                $optNameKey = strtolower(trim($attr['name'] ?? ''));
                $valueKey = strtolower(trim($attr['value'] ?? ''));
                if ($optNameKey === '' || $valueKey === '') {
                    continue;
                }

                $idx = $optionNameToIndex[$optNameKey] ?? null;
                if ($idx !== null && isset($optionValueMap[$idx][$valueKey])) {
                    $optionValueIds[] = $optionValueMap[$idx][$valueKey];
                }
            }

            // Normalize key for matching existing variants (order-independent)
            $optionValueIds = collect($optionValueIds)
                ->map(fn($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            // Safety: reject stale option_value_id that was deleted in this same request
            $validSet = array_flip(array_map('intval', $allKeptValueIds));
            foreach ($optionValueIds as $id) {
                if (!isset($validSet[(int) $id])) {
                    throw ValidationException::withMessages([
                        'combinations' => 'Kombinasi berisi opsi varian yang sudah dihapus. Refresh halaman lalu coba lagi.',
                    ]);
                }
            }

            $comboKey = implode('-', $optionValueIds);
            if (isset($seenIncomingComboKeys[$comboKey])) {
                throw ValidationException::withMessages([
                    'combinations' => 'Kombinasi variasi tidak boleh duplikat.',
                ]);
            }
            $seenIncomingComboKeys[$comboKey] = true;

            // Priority matching:
            // 1) If client sends id and exists -> update it
            // 2) Else, if combination key matches existing -> update it (keeps id stable)
            // 3) Else -> create new
            $incomingVariantId = !empty($combo['id']) ? (int) $combo['id'] : null;
            if ($incomingVariantId) {
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('id', $incomingVariantId)
                    ->first();
            } else {
                $variant = null;
            }

            if ($variant) {
                // matched by id
            } elseif (array_key_exists($comboKey, $existingVariantsByComboKey)) {
                $variant = $existingVariantsByComboKey[$comboKey];
            } else {
                $variant = $product->variants()->create();
            }

            $variant->update([
                'sku' => $combo['sku'] ?? null,
                'price' => $combo['price'],
                'stock' => $combo['stock'],
            ]);

            // Keep optionValues in sync (no-op when unchanged)
            $variant->optionValues()->sync($optionValueIds);
            $keptVariantIds[] = $variant->id;
        }

        // DELETE VARIANT yang dihapus user
        $product->variants()
            ->whereNotIn('id', $keptVariantIds)
            ->each(function (ProductVariant $variant) {
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

        // Track addon ids used by this product BEFORE update, to cleanup orphans after changes
        $beforeAddonIds = AddonGroupOption::query()
            ->whereIn(
                'addon_group_id',
                AddonGroup::query()->where('product_id', $product->id)->select('id')
            )
            ->pluck('addon_id')
            ->unique()
            ->values()
            ->all();

        /**
         * =================================================
         * 1️⃣ VALIDASI DUPLIKAT NAMA GROUP (CASE INSENSITIVE)
         * =================================================
         */
        $groupNameMap = [];

        foreach ($groups as $groupData) {
            $groupName = strtolower(trim($groupData['name']));

            if (isset($groupNameMap[$groupName])) {
                throw ValidationException::withMessages([
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
                    throw ValidationException::withMessages([
                        'add_on_groups' =>
                            "Nama opsi '{$optionData['name']}' pada grup '{$groupData['name']}' tidak boleh sama.",
                    ]);
                }

                $optionNameMap[$optionName] = true;

                $groupOption = null;

                // 🔐 TERIMA ID OPTION HANYA JIKA INTEGER (ID DB)
                if (isset($optionData['id']) && is_int($optionData['id'])) {
                    $groupOption = $addonGroup->options()->find($optionData['id']);
                }

                // 🔁 MASTER ADDON (GLOBAL PER MERCHANT)
                // Jika option sudah ada (punya id), maka rename addon existing agar ID tetap.
                // Jika option baru, pakai firstOrCreate by name.
                $addonName = trim($optionData['name']);
                if ($groupOption && $groupOption->addon) {
                    $addonMaster = $groupOption->addon;

                    // Safety: pastikan addon milik merchant yang sama
                    if ((int) $addonMaster->merchant_id === (int) $product->merchant_id) {
                        if ($addonMaster->addon_name !== $addonName) {
                            $addonMaster->update(['addon_name' => $addonName]);
                        }
                    } else {
                        // Fallback (shouldn't happen): keep behavior global by merchant
                        $addonMaster = $product->merchant->addons()->firstOrCreate(
                            ['addon_name' => $addonName],
                            ['addon_name' => $addonName]
                        );
                    }
                } else {
                    $addonMaster = $product->merchant->addons()->firstOrCreate(
                        ['addon_name' => $addonName],
                        ['addon_name' => $addonName]
                    );
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

        // Cleanup addon master yang jadi orphan setelah update (mis. opsi dihapus / sebelumnya sempat bikin addon baru)
        $this->cleanupOrphanAddons($beforeAddonIds, (int) $product->merchant_id);
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
                ->each(function (Image $img) {
                    $this->deleteImageFileIfExists($img->image_path);
                    $img->delete();
                });
        } elseif ($hasNew) {
            // Semua gambar lama dihapus jika user upload baru tanpa existing_images
            Image::where('imageable_type', 'product')
                ->where('imageable_id', $product->id)
                ->each(function (Image $img) {
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


    public function updateStatus(Request $request, Merchant $merchant, Product $product)
    {
        $data = $request->validate([
            'status' => ['required', 'in:published,archived'],
        ]);

        // 1) Merchant-level permission
        $this->authorize('manageProduct', $merchant);

        if ((int) $product->merchant_id !== (int) $merchant->id) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        // 2) Product-level permission
        $this->authorize('manage', $product);

        // 3) Block publishing if product has active violation
        if ($data['status'] === 'published' && $this->moderationService->hasActiveProductSanction($product)) {
            return $this->productPublishBlockedResponse($product);
        }

        try {
            $product->update(['status' => $data['status']]);
            return ApiResponse::success(null, 'Status produk diperbarui.', 200);
        } catch (\Exception $e) {
            return ApiResponse::error('Gagal memperbarui status produk.', 500, [$e->getMessage()]);
        }
    }

    // Delete product (also deletes images files)
    // Use slug instead of id
    public function destroy(Request $request, Merchant $merchant, Product $product)
    {
        // 1) Merchant-level permission
        $this->authorize('manageProduct', $merchant);

        if ((int) $product->merchant_id !== (int) $merchant->id) {
            return ApiResponse::error('Produk tidak ditemukan.', 404);
        }

        // 2) Product-level permission
        $this->authorize('manage', $product);

        // Ambil addon_id yang dipakai produk ini sebelum delete (FK cascade akan menghapus groups/options)
        $addonIds = $this->getAddonIdsForProduct($product);

        foreach ($product->images as $img) {
            $this->deleteImageFileIfExists($img->image_path);
        }
        $product->delete();

        // Hapus addon master yang sudah tidak dipakai oleh addon_group_options manapun
        $this->cleanupOrphanAddons($addonIds, (int) $merchant->id);

        return ApiResponse::success(null, 'Produk dihapus.', 200);
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

    private function getAddonIdsForProduct(Product $product): array
    {
        return AddonGroupOption::query()
            ->whereIn(
                'addon_group_id',
                AddonGroup::query()->where('product_id', $product->id)->select('id')
            )
            ->pluck('addon_id')
            ->unique()
            ->values()
            ->all();
    }

    private function cleanupOrphanAddons(array $addonIds, int $merchantId): void
    {
        if (empty($addonIds)) {
            return;
        }

        $stillUsedIds = AddonGroupOption::query()
            ->whereIn('addon_id', $addonIds)
            ->distinct()
            ->pluck('addon_id')
            ->all();

        $orphanIds = array_values(array_diff($addonIds, $stillUsedIds));
        if (empty($orphanIds)) {
            return;
        }

        Addon::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('id', $orphanIds)
            ->delete();
    }

    public function exportExcel(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0', 'gte:min_stock'],
            'sort_by' => ['nullable', 'in:newest,oldest,name_asc,name_desc,price_asc,price_desc,stock_asc,stock_desc'],
        ]);

        $this->authorize('manageProduct', $merchant);

        $merchantId = $merchant->id;

        // Build same query and get full collection (no pagination for export)
        $query = $this->buildFilteredProductQuery($merchantId, $data);

        // Eager load any relations as needed and get results
        $products = $query->get();

        $fileName = 'products-' . now()->format('Ymd-His') . '.xlsx';

        // ProductsExport expects merchantId (int), not collection
        return Excel::download(new ProductsExport($merchantId, $data), $fileName);
    }


    public function exportPdf(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0', 'gte:min_stock'],
            'sort_by' => ['nullable', 'in:newest,oldest,name_asc,name_desc,price_asc,price_desc,stock_asc,stock_desc'],
        ]);

        $this->authorize('manageProduct', $merchant);

        $merchantId = $merchant->id;

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
    public function bulkDelete(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'product_slugs' => ['required', 'array', 'min:1'],
            'product_slugs.*' => ['required', 'string', 'exists:products,slug'],
        ]);

        $user = $request->user();
        $slugs = $data['product_slugs'];

        $this->authorize('manageProduct', $merchant);

        $merchantId = $merchant->id;

        // Get products that belong to this merchant
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products */
        $products = Product::where('merchant_id', $merchantId)
            ->whereIn('slug', $slugs)
            ->get();

        // Slugs that exist but not belong to this merchant will be excluded above.
        $unauthorizedCount = max(0, count($slugs) - $products->count());
        $deletedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($products as $product) {
                /** @var \App\Models\Product $product */
                // Product-level permission (policy) without throwing mid-loop
                $ability = Gate::forUser($user)->inspect('manage', $product);
                if ($ability->denied()) {
                    $unauthorizedCount++;
                    continue;
                }

                // Delete product files
                $this->cleanupProductFiles($product);

                // Collect addon ids used by this product before deleting (FK cascades will remove group/options)
                $addonIds = $this->getAddonIdsForProduct($product);

                // Delete product record
                $product->delete();

                // Cleanup orphan addon masters per product
                $this->cleanupOrphanAddons($addonIds, (int) $merchantId);
                $deletedCount++;
            }

            DB::commit();

            return ApiResponse::success([
                'deleted_count' => $deletedCount,
                'unauthorized_count' => $unauthorizedCount,
            ], "Berhasil menghapus {$deletedCount} produk", 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk delete products failed', [
                'error' => $e->getMessage(),
                'slugs' => $slugs,
            ]);

            return ApiResponse::error('Gagal menghapus produk.', 500, [$e->getMessage()]);
        }
    }

    /**
     * Bulk update product status
     * POST /api/products/bulk-update-status
     */
    public function bulkUpdateStatus(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'product_slugs' => ['required', 'array', 'min:1'],
            'product_slugs.*' => ['required', 'string', 'exists:products,slug'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        $user = $request->user();
        $slugs = $data['product_slugs'];
        $newStatus = $data['status'];


        $this->authorize('manageProduct', $merchant);

        $merchantId = $merchant->id;

        // Get products that belong to this merchant
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products */
        $products = Product::where('merchant_id', $merchantId)
            ->whereIn('slug', $slugs)
            ->get();

        // Slugs that exist but not belong to this merchant will be excluded above.
        $unauthorizedCount = max(0, count($slugs) - $products->count());
        $updatedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($products as $product) {
                /** @var \App\Models\Product $product */
                // Product-level permission (policy) without throwing mid-loop
                $ability = Gate::forUser($user)->inspect('manage', $product);
                if ($ability->denied()) {
                    $unauthorizedCount++;
                    continue;
                }

                // Block publishing if product has active violation
                if ($newStatus === 'published' && $this->moderationService->hasActiveProductSanction($product)) {
                    $unauthorizedCount++;
                    continue;
                }

                // Update status
                $product->update(['status' => $newStatus]);
                $updatedCount++;
            }

            DB::commit();

            return ApiResponse::success([
                'updated_count' => $updatedCount,
                'unauthorized_count' => $unauthorizedCount,
                'new_status' => $newStatus,
            ], "Berhasil mengubah status {$updatedCount} produk menjadi {$newStatus}", 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk update status failed', [
                'error' => $e->getMessage(),
                'slugs' => $slugs,
                'status' => $newStatus,
            ]);

            return ApiResponse::error('Gagal mengubah status produk.', 500, [$e->getMessage()]);
        }
    }

    private function buildFilteredProductQuery(int $merchantId, array $data)
    {
        $query = Product::query()
            ->where('merchant_id', $merchantId);


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
            /** @var \App\Models\Image $image */
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
}
