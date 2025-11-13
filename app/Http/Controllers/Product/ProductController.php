<?php

namespace App\Http\Controllers\Product;

use App\Models\Image;
use App\Models\Product;
use App\Models\Merchant;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductController
{
    private const ALLOWED_SEGMENTS = ['UMKM Toko', 'UMKM Kuliner'];
    private const ALLOWED_SEGMENT_IDS = [1, 2];
    private const VALID_STATUSES = ['draft', 'published', 'archived'];

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
                'categories:id,category_name',
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
                'categories:id,category_name,slug',

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
                    $q->with(['options' => function ($oq) {
                        $oq->with('addon:id,addon_name')
                            ->select('id', 'addon_group_id', 'addon_id', 'addon_price', 'addon_stock')
                            ->whereRaw('(addon_stock IS NULL OR addon_stock > 0)');
                    }])
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

        if (!$variant) {
            return response()->json([
                'message' => 'Variant dengan kombinasi ini tidak tersedia.',
                'available' => false,
            ], 404);
        }

        if ($variant->stock <= 0) {
            return response()->json([
                'message' => 'Variant ini sedang habis.',
                'available' => false,
                'variant' => $variant->only(['id', 'price', 'stock', 'sku']),
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
            ->with(['coverImage', 'categories:id,category_name']);

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

    // List products by merchant (owner only, allowed segments)
    public function index(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'q' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $merchantOrError = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
        if (is_array($merchantOrError) && isset($merchantOrError['error'])) {
            return $merchantOrError['error'];
        }
        $merchant = $merchantOrError;

        $query = Product::query()
            ->where('merchant_id', $merchant->id)
            ->with(['coverImage']);

        if (!empty($data['q'])) {
            $query->where(function ($q) use ($data) {
                $q->where('name', 'like', '%' . $data['q'] . '%')
                    ->orWhere('slug', 'like', '%' . $data['q'] . '%');
            });
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $perPage = $data['per_page'] ?? 15;

        return response()->json($query->paginate($perPage));
    }

    // Create product + optional images (default: draft)
    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published,archived'], // default: draft
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'max:5120'],
            'cover_image_index' => ['nullable', 'integer', 'min:0'],
        ]);

        // Validasi: jika status=published, wajib ada gambar
        if (
            isset($data['status']) &&
            $data['status'] === 'published' &&
            empty($data['images'])
        ) {
            return response()->json([
                'message' => 'Produk harus memiliki setidaknya satu gambar untuk dipublikasikan.',
            ], 422);
        }

        $merchantOrError = $this->findOwnedMerchantOrAbort($request->user()->id, (int) $data['merchant_id']);
        if (is_array($merchantOrError) && isset($merchantOrError['error'])) {
            return $merchantOrError['error'];
        }
        $merchant = $merchantOrError;

        $product = Product::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(6),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'draft', // default draft
        ]);

        // Optional: upload images
        if (!empty($data['images'])) {
            $this->storeUploadedImages($product, $data['images'], $data['cover_image_index'] ?? null);
        }

        return response()->json($product->load('coverImage', 'images'), 201);
    }

    // Get product (owner only)
    public function show(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        return response()->json(
            $product->load([
                'coverImage',
                'images' => fn($q) => $q->orderBy('display_order'),
                'options.values',
                'variants.optionValues',
                'categories',
                'addonGroups.options.addon', // ✅ tambahkan
            ])
        );
    }

    // Update product (bisa update status)
    public function update(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:products,slug,' . $product->id],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);

        $product->update($data);

        // Refresh + eager load dalam 1 query
        $product->refresh();
        $product->load('coverImage', 'images');

        return response()->json($product);
    }

    // Publish product (shortcut)
    public function publish(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        // Validasi: produk harus punya gambar minimal 1 (opsional)
        if ($product->images()->count() === 0) {
            return response()->json(['message' => 'Produk harus memiliki setidaknya satu gambar.'], 422);
        }

        $product->update(['status' => 'published']);

        return response()->json(['message' => 'Produk diterbitkan', 'product' => $product->fresh()]);
    }

    // Archive product (shortcut)
    public function archive(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $product->update(['status' => 'archived']);

        return response()->json(['message' => 'Produk diarsipkan', 'product' => $product->fresh()]);
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

    // Delete a specific image
    public function destroyImage(Request $request, Product $product, Image $image)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $imageError = $this->abortIfImageNotBelongsToProduct($product, $image);
        if ($imageError)
            return $imageError;

        $this->deleteImageFileIfExists($image->image_path);
        $image->delete();

        return response()->json(['message' => 'Gambar dihapus.']);
    }

    // Set cover image
    public function setCoverImage(Request $request, Product $product, Image $image)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $imageError = $this->abortIfImageNotBelongsToProduct($product, $image);
        if ($imageError)
            return $imageError;

        // Reset cover lama
        $product->images()->where('is_cover', true)->update(['is_cover' => false]);

        // Set cover baru ke display_order 0, geser sisanya
        $this->reorderAfterSetCover($product, $image);

        return response()->json(['cover' => $product->fresh()->coverImage]);
    }

    // Reorder images
    public function reorderImages(Request $request, Product $product)
    {
        $error = $this->abortIfNotOwnerOrNotAllowed($request->user()->id, $product->merchant_id);
        if ($error)
            return $error;

        $data = $request->validate([
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.id' => ['required', 'integer', 'exists:images,id'],
            'orders.*.display_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $ids = collect($data['orders'])->pluck('id')->all();
        $imageCount = $product->images()->whereIn('id', $ids)->count();

        if ($imageCount !== count($ids)) {
            return response()->json(['message' => 'Beberapa gambar tidak ditemukan pada produk ini.'], 422);
        }

        DB::transaction(function () use ($data, $product, $ids) {
            $now = now();
            $cases = [];
            $bindings = [];

            foreach ($data['orders'] as $item) {
                $cases[] = "WHEN ? THEN ?";
                $bindings[] = $item['id'];
                $bindings[] = $item['display_order'];
            }

            $caseSql = implode(' ', $cases);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            DB::update("
                UPDATE images
                SET display_order = CASE id {$caseSql} END,
                    updated_at = ?
                WHERE id IN ({$placeholders})
            ", array_merge($bindings, [$now], $ids));

            DB::connection()->flushQueryLog();
            $this->syncCoverFlagOptimized($product);
        });

        // Refresh images dari DB
        return response()->json(
            $product->images()->orderBy('display_order')->get()
        );
    }

    /**
     * Optimized: Update is_cover dalam 1 query tanpa query intermediate
     */
    private function syncCoverFlagOptimized(Product $product): void
    {
        $now = now();

        // Reset semua is_cover ke false
        DB::update("
            UPDATE images
            SET is_cover = 0, updated_at = ?
            WHERE imageable_type = ? AND imageable_id = ?
        ", [$now, 'product', $product->id]);

        // Set is_cover=true untuk image dengan display_order terkecil
        DB::update("
            UPDATE images
            SET is_cover = 1, updated_at = ?
            WHERE id = (
                SELECT id FROM (
                    SELECT id FROM images
                    WHERE imageable_type = ? AND imageable_id = ?
                    ORDER BY display_order ASC, id ASC
                    LIMIT 1
                ) AS temp
            )
        ", [$now, 'product', $product->id]);
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

    private function findOwnedMerchantOrAbort(int $userId, int $merchantId): Merchant
    {
        try {
            $merchant = Merchant::query()->findOrFail($merchantId);
        } catch (ModelNotFoundException $e) {
            abort(404, 'UMKM tidak ditemukan.');
        }

        if ((int) ($merchant->user_id ?? 0) !== $userId) {
            abort(403, 'UMKM tidak sah. Anda bukan pemilik UMKM ini.');
        }

        $segmentId = $merchant->segmentation_id
            ?? $merchant->segment_id
            ?? optional($merchant->segmentation)->id
            ?? null;

        if (!in_array((int) $segmentId, self::ALLOWED_SEGMENT_IDS, true)) {
            abort(403, 'Segment UMKM tidak diizinkan untuk mengelola produk.');
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
        $baseOrder = (int) ($product->images()->max('display_order') ?? -1);
        $imagesToInsert = [];
        $now = now();

        foreach ($files as $idx => $file) {
            $path = $file->store("products/{$product->id}", 'public');

            $imagesToInsert[] = [
                'imageable_type' => 'product', // atau Product::class jika ada morph map
                'imageable_id' => $product->id,
                'image_path' => $path,
                'display_order' => $baseOrder + $idx + 1,
                'is_cover' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Batch insert (1 query untuk semua)
        DB::table('images')->insert($imagesToInsert);

        // Ambil kembali images yang baru dibuat
        $created = $product->images()
            ->where('created_at', '>=', $now)
            ->orderBy('id')
            ->get();

        // Set cover jika ada
        if ($coverIndex !== null && isset($created[$coverIndex])) {
            $this->reorderAfterSetCover($product, $created[$coverIndex]);
        }

        return $created->toArray();
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

    private function abortIfImageNotBelongsToProduct(Product $product, Image $image)
    {
        $expectedType = array_search(Product::class, Relation::morphMap()) ?: Product::class;

        if (
            !in_array($image->imageable_type, [$expectedType, Product::class], true) ||
            (int) $image->imageable_id !== (int) $product->id
        ) {
            return response()->json(['message' => 'Gambar tidak ditemukan pada produk ini.'], 404);
        }

        return null;
    }

    private function deleteImageFileIfExists(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function syncCoverFlag(Product $product): void
    {
        // Update only images yang is_cover=true (lebih efisien)
        $product->images()->where('is_cover', true)->update(['is_cover' => false]);

        $firstImage = $product->images()
            ->orderBy('display_order')
            ->orderBy('id')
            ->first(['id']); // hanya select id

        if ($firstImage) {
            DB::table('images')
                ->where('id', $firstImage->id)
                ->update(['is_cover' => true, 'updated_at' => now()]);
        }
    }
}