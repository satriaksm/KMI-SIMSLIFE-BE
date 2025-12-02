<?php

namespace App\Http\Controllers\Product;

use App\Models\Image;
use App\Models\Product;
use App\Models\Merchant;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Exports\ProductsExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Intervention\Image\ImageManager as ImageIntervention;

class ProductController
{
    private const ALLOWED_SEGMENT_IDS = [1, 2];
    private const MAX_VARIANTS = 50;
    private const MAX_OPTIONS = 2;
    private const MAX_ADDON_GROUPS = 10;

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
                'merchant:id,merchant_name,slug',
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

    /**
     * Public: Get product detail by slug (PDP - Product Detail Page)
     * No authentication required
     */
    public function publicShow(string $slug)
    {
        $product = Product::where('slug', $slug)
            ->where('status', 'published')
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with([
                'coverImage',
                'images' => fn($q) => $q->orderBy('display_order'),
                'merchant:id,merchant_name,slug,description',
                'categories:id,name,slug',

                // Options for variant selection
                'options' => function ($q) {
                    $q->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')])
                        ->select('id', 'product_id', 'option_name', 'uses_image')
                        ->orderBy('id');
                },

                // Variants (only in-stock)
                'variants' => function ($q) {
                    $q->with(['optionValues:id,product_option_id,option_value'])
                        ->select('id', 'product_id', 'stock', 'price', 'sku')
                        ->where('stock', '>', 0)
                        ->orderBy('price', 'asc');
                },

                // Addon groups
                'addonGroups' => function ($q) {
                    $q->with([
                        'options' => function ($oq) {
                            $oq->with('addon:id,addon_name')
                                ->select('id', 'addon_group_id', 'addon_id', 'addon_price', 'addon_stock')
                                ->whereRaw('(addon_stock IS NULL OR addon_stock > 0)');
                        }
                    ])
                        ->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection');
                },
            ])
            ->firstOrFail();

        // Calculate price range
        $variants = $product->variants;
        $priceRange = [
            'min' => $variants->min('price'),
            'max' => $variants->max('price'),
        ];

        return response()->json([
            'product' => $product,
            'price_range' => $priceRange,
            'total_stock' => $variants->sum('stock'),
            'has_variants' => $variants->isNotEmpty(),
            'has_addons' => $product->addonGroups->isNotEmpty(),
        ]);
    }

    /**
     * Public: Get variant by selected option values
     * Real-time availability check when user selects options
     */
    public function publicGetVariant(Request $request, string $slug)
    {
        $product = Product::where('slug', $slug)
            ->where('status', 'published')
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
            ->firstOrFail(['id', 'merchant_name', 'slug', 'description']);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::where('merchant_id', $merchant->id)
            ->where('status', 'published')
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
        $products = Product::where('status', 'published')
            ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
            ->with(['coverImage', 'merchant:id,merchant_name'])
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
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();

            if (!$merchant) {
                return response()->json([
                    'message' => 'Anda tidak memiliki UMKM atau segment UMKM tidak diizinkan.',
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

        $perPage = $data['per_page'] ?? 15;
        $result = $query->paginate($perPage);

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
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['required', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'sub_categories' => ['nullable', 'array', 'max:4'],
            'sub_categories.*' => ['integer', 'exists:categories,id'],
            'min_purchase' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,published'],

            // Product images (REQUIRED minimal 1)
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*.file' => ['required', 'file', 'image', 'max:5120'],
            'images.*.order' => ['required', 'integer', 'min:0'],
            'cover_image_index' => ['required', 'integer', 'min:0'],

            // ✅ NEW: SKU untuk produk tanpa variasi
            'sku' => ['nullable', 'string', 'max:100', 'unique:product_variants,sku'],

            // Non-variant: harga & stok langsung
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],

            // Variants (optional)
            'variants' => ['nullable', 'array', 'max:2'],
            'variants.*.name' => ['required', 'string', 'max:100'],
            'variants.*.uses_images' => ['required', 'in:0,1'],
            'variants.*.options' => ['required', 'array', 'min:1'],
            'variants.*.options.*.name' => ['required', 'string', 'max:100'],
            'variants.*.options.*.images' => ['nullable', 'array', 'max:1'],
            'variants.*.options.*.images.*.file' => ['file', 'image', 'max:5120'],

            // Combinations (jika pakai variants)
            'combinations' => ['nullable', 'array', 'max:' . self::MAX_VARIANTS],
            'combinations.*.combination' => ['required', 'string', 'max:255'],
            'combinations.*.sku' => ['nullable', 'string', 'max:100', 'unique:product_variants,sku'], // ✅ UPDATED: Add unique validation
            'combinations.*.price' => ['required', 'numeric', 'min:0'],
            'combinations.*.stock' => ['required', 'integer', 'min:0'],
            'combinations.*.attributes' => ['required', 'array'],
            'combinations.*.attributes.*.name' => ['required', 'string'],
            'combinations.*.attributes.*.value' => ['required', 'string'],

            // Addon groups
            'add_on_groups' => ['nullable', 'array', 'max:' . self::MAX_ADDON_GROUPS],
            'add_on_groups.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.min_selection' => ['required', 'integer', 'min:0'],
            'add_on_groups.*.max_selection' => ['required', 'integer', 'min:1'],
            'add_on_groups.*.options' => ['required', 'array', 'min:1'],
            'add_on_groups.*.options.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.options.*.price' => ['required', 'numeric', 'min:0'],
        ]);
        // Validasi merchant ownership & segment
        $merchantOrError = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
        if (is_array($merchantOrError) && isset($merchantOrError['error'])) {
            return $merchantOrError['error'];
        }
        $merchant = $merchantOrError;

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

        DB::beginTransaction();
        try {
            // 1. CREATE PRODUCT
            $product = Product::create([
                'merchant_id' => $merchant->id,
                'name' => $data['name'],
                'slug' => $data['slug'] ?? $this->generateUniqueSlug($data['name']),
                'description' => $data['description'],
                'min_purchase' => $data['min_purchase'],
                'status' => $data['status'] ?? 'draft',
            ]);

            // 2. ATTACH CATEGORIES
            $categoryIds = [$data['category_id']];
            if (!empty($data['sub_categories'])) {
                $categoryIds = array_merge($categoryIds, $data['sub_categories']);
            }
            $product->categories()->attach(array_unique($categoryIds));

            // 3. UPLOAD PRODUCT IMAGES
            $this->storeProductImages($product, $data['images'], $data['cover_image_index']);

            // 4. CREATE VARIANTS OR DIRECT PRICING
            if ($useVariants) {
                // Create options + option values
                $optionMap = $this->createProductOptions($product, $data['variants']);

                // Create variants (combinations)
                $this->createProductVariants($product, $data['combinations'], $optionMap);
            } else {
                // Direct pricing (single variant tanpa options)
                $product->variants()->create([
                    'sku' => $data['sku'] ?? null,
                    'price' => $data['price'],
                    'stock' => $data['stock'],
                ]);
            }

            // 5. CREATE ADDON GROUPS (optional)
            if (!empty($data['add_on_groups'])) {
                $this->createAddonGroups($product, $merchant, $data['add_on_groups']);
            }

            DB::commit();

            return response()->json(
                $product->load([
                    'coverImage',
                    'images',
                    'categories',
                    'options.values',
                    'variants.optionValues',
                    'addonGroups.options.addon',
                ]),
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();

            // Cleanup uploaded files
            if (isset($product)) {
                $this->cleanupProductFiles($product);
            }

            return response()->json([
                'message' => 'Gagal membuat produk.',
                'error' => $e->getMessage(),
            ], 500);
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
            // Tentukan selection_type berdasarkan max_selection
            $selectionType = ($groupData['max_selection'] === 1) ? 'single' : 'multiple';

            // ✅ FIXED: Langsung gunakan min_selection dari input
            // min_selection >= 1 = wajib pilih minimal X
            // min_selection = 0 = opsional (boleh tidak pilih)
            $addonGroup = $product->addonGroups()->create([
                'addon_group_name' => $groupData['name'],
                'selection_type' => $selectionType,
                'min_selection' => $groupData['min_selection'], // ✅ Gunakan langsung dari input
                'max_selection' => $groupData['max_selection'],
            ]);

            // Create addons + attach to group
            foreach ($groupData['options'] as $optionData) {
                // Create or find addon
                $addon = $merchant->addons()->firstOrCreate(
                    ['addon_name' => $optionData['name']],
                    ['addon_name' => $optionData['name']]
                );

                // Attach addon to group
                $addonGroup->options()->create([
                    'addon_id' => $addon->id,
                    'addon_price' => $optionData['price'],
                    'addon_stock' => null, // unlimited by default
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
     */
    public function show(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        return response()->json(
            $product->load([
                'coverImage',
                'images' => fn($q) => $q->orderBy('display_order'),
                'categories',

                // Options for variant selection
                'options' => function ($q) {
                    $q->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')])
                        ->select('id', 'product_id', 'option_name', 'uses_image')
                        ->orderBy('id');
                },

                // Variants with option values
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

                // ✅ FIXED: Addon groups with COMPLETE nested eager loading
                'addonGroups' => function ($q) {
                    $q->with([
                        'options' => function ($oq) {
                            // ✅ ADD THIS LINE (was missing!)
                            $oq->with('addon:id,addon_name')
                                ->select('id', 'addon_group_id', 'addon_id', 'addon_price', 'addon_stock');
                        }
                    ])
                        ->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection')
                        ->orderBy('id');
                },
            ])
        );
    }

    /**
     * ✅ UPDATED: Full product update with images, variants, addons
     */
    public function update(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $data = $request->validate([
            // Basic info
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug,' . $product->id],
            'description' => ['sometimes', 'required', 'string'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'sub_categories' => ['nullable', 'array', 'max:4'],
            'sub_categories.*' => ['integer', 'exists:categories,id'],
            'min_purchase' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:draft,published,archived'],

            // Images (new uploads)
            'images' => ['nullable', 'array', 'max:6'],
            'images.*.file' => ['required', 'file', 'image', 'max:5120'],
            'images.*.order' => ['required', 'integer', 'min:0'],

            // Existing images (to keep)
            'existing_images' => ['nullable', 'array'],
            'existing_images.*.id' => ['required', 'integer', 'exists:images,id'],
            'existing_images.*.order' => ['required', 'integer', 'min:0'],
            'existing_images.*.is_cover' => ['required', 'boolean'],

            // ✅ NEW: SKU untuk produk tanpa variasi
            'sku' => ['nullable', 'string', 'max:100', 'unique:product_variants,sku'],
            // Non-variant pricing
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],

            // Variants
            'variants' => ['nullable', 'array', 'max:2'],
            'variants.*.id' => ['nullable', 'integer'], // null for new variants
            'variants.*.name' => ['required', 'string', 'max:100'],
            'variants.*.uses_images' => ['required', 'in:0,1'],
            'variants.*.options' => ['required', 'array', 'min:1'],
            'variants.*.options.*.id' => ['nullable', 'integer'],
            'variants.*.options.*.name' => ['required', 'string', 'max:100'],
            'variants.*.options.*.images' => ['nullable', 'array', 'max:1'],
            'variants.*.options.*.images.*.file' => ['file', 'image', 'max:5120'],
            'variants.*.options.*.existing_images' => ['nullable', 'array'],

            // Combinations
            'combinations' => ['nullable', 'array', 'max:' . self::MAX_VARIANTS],
            'combinations.*.combination' => ['required', 'string'],
            'combinations.*.sku' => ['nullable', 'string', 'max:100'],
            'combinations.*.price' => ['required', 'numeric', 'min:0'],
            'combinations.*.stock' => ['required', 'integer', 'min:0'],
            'combinations.*.attributes' => ['required', 'array'],

            // Add-ons
            'add_on_groups' => ['nullable', 'array', 'max:' . self::MAX_ADDON_GROUPS],
            'add_on_groups.*.id' => ['nullable', 'integer'], // null for new groups
            'add_on_groups.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.min_selection' => ['required', 'integer', 'min:0'],
            'add_on_groups.*.max_selection' => ['required', 'integer', 'min:1'],
            'add_on_groups.*.options' => ['required', 'array', 'min:1'],
            'add_on_groups.*.options.*.id' => ['nullable', 'integer'],
            'add_on_groups.*.options.*.name' => ['required', 'string', 'max:100'],
            'add_on_groups.*.options.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        DB::beginTransaction();
        try {
            // 1. UPDATE BASIC INFO
            $updateData = [
                'name' => $data['name'] ?? $product->name,
                'description' => $data['description'] ?? $product->description,
                'min_purchase' => $data['min_purchase'] ?? $product->min_purchase,
            ];

            if (!empty($data['slug'])) {
                $updateData['slug'] = $data['slug'];
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
            $useVariants = !empty($data['variants']);

            if ($useVariants) {
                $this->updateProductVariants($product, $data);
            } else {
                // Update single variant (no options)
                $variant = $product->variants()->first();
                if ($variant) {
                    $variant->update([
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
            }

            // 5. UPDATE ADDON GROUPS
            if (isset($data['add_on_groups'])) {
                $this->updateAddonGroups($product, $data['add_on_groups']);
            }

            DB::commit();

            return response()->json(
                $product->fresh()->load([
                    'coverImage',
                    'images',
                    'categories',
                    'options.values',
                    'variants.optionValues',
                    'addonGroups.options.addon',
                ])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal memperbarui produk.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ HELPER: Update product images
     */
    private function updateProductImages(Product $product, array $data): void
    {
        // ✅ Collect all images with their final order
        $allImages = [];

        // Add existing images
        if (!empty($data['existing_images'])) {
            foreach ($data['existing_images'] as $existingImg) {
                $allImages[$existingImg['order']] = [
                    'type' => 'existing',
                    'id' => $existingImg['id'],
                ];
            }
        }

        // Add new images
        if (!empty($data['images'])) {
            foreach ($data['images'] as $newImg) {
                $allImages[$newImg['order']] = [
                    'type' => 'new',
                    'file' => $newImg['file'],
                ];
            }
        }

        // Sort by order
        ksort($allImages);

        // ✅ Delete images not in the final list
        $keepIds = [];
        foreach ($allImages as $img) {
            if ($img['type'] === 'existing') {
                $keepIds[] = $img['id'];
            }
        }

        $imagesToDelete = $product->images()->whereNotIn('id', $keepIds)->get();
        foreach ($imagesToDelete as $image) {
            $this->deleteImageFileIfExists($image->image_path);
            $image->delete();
        }

        // ✅ Process images in order - FIRST IS ALWAYS COVER
        $displayOrder = 0;
        foreach ($allImages as $imageData) {
            $isCover = ($displayOrder === 0); // ✅ First image is cover

            if ($imageData['type'] === 'existing') {
                // Update existing image
                Image::where('id', $imageData['id'])->update([
                    'display_order' => $displayOrder,
                    'is_cover' => $isCover,
                ]);
            } else {
                // Upload new image
                $file = $imageData['file'];
                $path = $file->store("products/{$product->id}", 'public');

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
    private function updateProductVariants(Product $product, array $data): void
    {
        $merchant = $product->merchant;

        // Get existing option IDs and variant IDs
        $keepOptionIds = [];
        $keepVariantIds = [];

        // Track which options to keep
        foreach ($data['variants'] as $variantData) {
            if (!empty($variantData['id'])) {
                $keepOptionIds[] = $variantData['id'];
            }
        }

        // Delete options not in keep list
        $product->options()
            ->whereNotIn('id', $keepOptionIds)
            ->each(function ($option) {
                // Delete option value images
                foreach ($option->values as $value) {
                    if ($value->image_path) {
                        $this->deleteImageFileIfExists($value->image_path);
                    }
                }
                $option->delete();
            });

        // Update or create options
        $optionMap = [];
        foreach ($data['variants'] as $variantIndex => $variantData) {
            $usesImages = ($variantIndex === 0) && ((int) $variantData['uses_images'] === 1);

            // Update or create option
            if (!empty($variantData['id'])) {
                $option = $product->options()->find($variantData['id']);
                $option->update([
                    'option_name' => $variantData['name'],
                    'uses_image' => $usesImages,
                ]);
            } else {
                $option = $product->options()->create([
                    'option_name' => $variantData['name'],
                    'uses_image' => $usesImages,
                ]);
            }

            // Get existing value IDs
            $keepValueIds = [];
            foreach ($variantData['options'] as $optData) {
                if (!empty($optData['id'])) {
                    $keepValueIds[] = $optData['id'];
                }
            }

            // Delete values not in keep list
            $option->values()
                ->whereNotIn('id', $keepValueIds)
                ->each(function ($value) {
                    if ($value->image_path) {
                        $this->deleteImageFileIfExists($value->image_path);
                    }
                    $value->delete();
                });

            // Update or create values
            $valueMap = [];
            foreach ($variantData['options'] as $optData) {
                if (!empty($optData['id'])) {
                    // Update existing
                    $value = $option->values()->find($optData['id']);
                    $value->update(['option_value' => $optData['name']]);
                } else {
                    // Create new
                    $value = $option->values()->create([
                        'option_value' => $optData['name'],
                    ]);
                }

                // Handle image upload
                if ($usesImages && !empty($optData['images'])) {
                    $imageFile = $optData['images'][0]['file'] ?? null;
                    if ($imageFile) {
                        // Delete old image
                        if ($value->image_path) {
                            $this->deleteImageFileIfExists($value->image_path);
                        }
                        // Upload new
                        $path = $imageFile->store("option-values/{$value->id}", 'public');
                        $value->update(['image_path' => $path]);
                    }
                }

                $valueMap[$optData['name']] = $value->id;
            }

            $optionMap[$variantData['name']] = [
                'option_id' => $option->id,
                'values' => $valueMap,
            ];
        }

        // Delete all existing variants (we'll recreate them)
        $product->variants()->delete();

        // Create new variants from combinations
        if (!empty($data['combinations'])) {
            foreach ($data['combinations'] as $combo) {
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
    }

    /**
     * ✅ HELPER: Update addon groups
     */
    private function updateAddonGroups(Product $product, array $groups): void
    {
        $merchant = $product->merchant;

        // Get existing group IDs to keep
        $keepGroupIds = array_filter(array_column($groups, 'id'));

        // Delete groups not in keep list
        $product->addonGroups()
            ->whereNotIn('id', $keepGroupIds)
            ->delete();

        // Update or create groups
        foreach ($groups as $groupData) {
            $selectionType = ($groupData['max_selection'] === 1) ? 'single' : 'multiple';

            if (!empty($groupData['id'])) {
                // Update existing
                $addonGroup = $product->addonGroups()->find($groupData['id']);
                $addonGroup->update([
                    'addon_group_name' => $groupData['name'],
                    'selection_type' => $selectionType,
                    'min_selection' => $groupData['min_selection'],
                    'max_selection' => $groupData['max_selection'],
                ]);
            } else {
                // Create new
                $addonGroup = $product->addonGroups()->create([
                    'addon_group_name' => $groupData['name'],
                    'selection_type' => $selectionType,
                    'min_selection' => $groupData['min_selection'],
                    'max_selection' => $groupData['max_selection'],
                ]);
            }

            // Get existing option IDs
            $keepOptionIds = array_filter(array_column($groupData['options'], 'id'));

            // Delete options not in keep list
            $addonGroup->options()
                ->whereNotIn('id', $keepOptionIds)
                ->delete();

            // Update or create options
            foreach ($groupData['options'] as $optionData) {
                // Find or create addon
                $addon = $merchant->addons()->firstOrCreate(
                    ['addon_name' => $optionData['name']],
                    ['addon_name' => $optionData['name']]
                );

                if (!empty($optionData['id'])) {
                    // Update existing
                    $addonGroup->options()->where('id', $optionData['id'])->update([
                        'addon_id' => $addon->id,
                        'addon_price' => $optionData['price'],
                    ]);
                } else {
                    // Create new
                    $addonGroup->options()->create([
                        'addon_id' => $addon->id,
                        'addon_price' => $optionData['price'],
                        'addon_stock' => null,
                    ]);
                }
            }
        }
    }

    public function updateStatus(Request $request, Product $product)
    {
        // Validasi input status
        $data = $request->validate([
            'status' => ['required', 'in:published,archived'], // Status yang valid
        ]);

        // Pastikan pengguna memiliki izin untuk mengubah produk ini
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error) {
            return $error;
        }

        try {
            // Perbarui status produk
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
    public function destroy(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        // delete physical files
        foreach ($product->images as $img) {
            $this->deleteImageFileIfExists($img->image_path);
        }
        $product->delete();

        return response()->json(['message' => 'Produk dihapus']);
    }

    // Add images to product
    public function storeImage(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $data = $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['file', 'image', 'max:5120'],
        ]);

        // Image pertama di upload ini jadi cover (index 0)
        $created = $this->storeUploadedImages($product, $data['images'], 0);

        return response()->json(['images' => $created, 'cover' => $product->fresh()->coverImage], 201);
    }

    /**
     * Get total possible combinations
     * Endpoint untuk FE preview kombinasi (optional)
     */
    public function getCombinationCount(Request $request, Product $product)
    {
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
        $productsTable = (new \App\Models\Product)->getTable();

        $variants = \App\Models\ProductVariant::query()
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
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();
            if (!$merchant) {
                return response()->json(['message' => 'Anda tidak memiliki UMKM atau segment UMKM tidak diizinkan.'], 403);
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
                ->whereIn('segmentation_id', self::ALLOWED_SEGMENT_IDS)
                ->first();
            if (!$merchant) {
                return response()->json(['message' => 'Anda tidak memiliki UMKM atau segment UMKM tidak diizinkan.'], 403);
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

}