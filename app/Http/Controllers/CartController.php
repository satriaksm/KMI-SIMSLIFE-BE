<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\CartItem;
use Illuminate\Http\Request;
use App\Models\ProductVariant;
use App\Models\AddonGroupOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Helpers\ApiResponse;


class CartController extends Controller
{
    private function applyImageSrcUrl($image, bool $isPublic)
    {
        if (!$image)
            return null;

        $image->src_url = $isPublic
            ? route('images.show', ['image' => $image->id])
            : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));

        $image->makeHidden([
            'imageable_id',
            'imageable_type',
            'image_path',
            'created_at',
            'updated_at',
        ]);

        return $image;
    }

    private function snapshotCoverImage(Product $product, CartItem $cartItem): ?string
    {
        $cover = $product->coverImage;
        if (!$cover || empty($cover->image_path)) {
            return null;
        }

        $disk = Storage::disk('public');
        $sourcePath = $cover->image_path;

        if (!$disk->exists($sourcePath)) {
            return null;
        }

        $userId = Auth::id() ?? 0;
        $userName = \Illuminate\Support\Str::slug(Auth::user()?->name ?? 'guest');
        $merchantSlug = $product->merchant?->slug ?? 'unknown-merchant';
        $itemSlug = \Illuminate\Support\Str::slug($product->name);
        
        $snapshotPath = "carts/{$userName}-{$userId}/{$merchantSlug}/{$itemSlug}-{$cartItem->id}.webp";

        try {
            $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
            $image = $manager->read($disk->path($sourcePath));
            $image->scaleDown(width: 300);
            $disk->put($snapshotPath, (string) $image->toWebp(80));
        } catch (\Exception $e) {
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp';
            $snapshotPath = "carts/{$userName}-{$userId}/{$merchantSlug}/{$itemSlug}-{$cartItem->id}.{$extension}";
            $disk->copy($sourcePath, $snapshotPath);
        }

        return $snapshotPath;
    }

    public function index()
    {
        $userId = Auth::id();

        // 1. Ambil Cart (Query tetap sama seperti sebelumnya)
        $carts = Cart::with([
            'merchant.addresses.district.city.province',
            'items.addons.addon',
            'items.addons.addonGroupOption',
            'items.itemable' => function ($q) {
                $q->with([
                    'coverImage' => fn($ciq) => $ciq->select('id', 'imageable_id', 'imageable_type', 'image_path'),
                    'categories' => fn($cq) => $cq->select('categories.id', 'categories.name'),
                    'options' => function ($oq) {
                        $oq->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')])
                            ->select('id', 'product_id', 'option_name', 'uses_image')
                            ->orderBy('id');
                    },
                    'variants' => function ($vq) {
                        $vq->with([
                            'optionValues' => function ($ovq) {
                                $ovq->select('product_option_values.id', 'product_option_id', 'option_value')
                                    ->join('product_options', 'product_option_values.product_option_id', '=', 'product_options.id')
                                    ->addSelect('product_options.option_name');
                            }
                        ])
                            ->select('id', 'product_id', 'sku', 'price', 'stock')
                            ->orderBy('price', 'asc');
                    },
                    'addonGroups' => function ($agq) {
                        $agq->with([
                            'options' => function ($aoq) {
                                $aoq->with('addon:id,addon_name')
                                    ->select('id', 'addon_group_id', 'addon_id', 'addon_price');
                            }
                        ])
                            ->select('id', 'product_id', 'addon_group_name', 'selection_type', 'min_selection', 'max_selection')
                            ->orderBy('id');
                    }
                ]);
            }
        ])
            ->where('user_id', $userId)
            ->latest()
            ->get();



        // 2. Mapping Data & Transformasi
        $cartStores = $carts->map(function ($cart) {

            // Format Alamat
            $address = $cart->merchant->addresses->first();
            $fullAddress = null;
            if ($address) {
                $parts = array_filter([
                    $address->detail,
                    $address->district?->name,
                    $address->city?->name,
                    $address->province?->name
                ]);
                $fullAddress = implode(', ', $parts);
            }

            return [
                'cart_id' => $cart->id,
                'merchant' => [
                    'id' => $cart->merchant->id,
                    'slug' => $cart->merchant->slug,
                    'name' => $cart->merchant->name,
                    'phone' => $cart->merchant->phone,
                    'address' => $fullAddress,
                    'logo' => $cart->merchant->logo_url ?? null,
                ],
                'items' => $cart->items->map(function ($item) {

                    $variant = $item->variant;

                    // =========================
                    // SNAPSHOT (STABIL)
                    // =========================
                    $snapshotAddons = $item->addons->map(function ($a) {
                        return [
                            'addon_id' => $a->addon_id,
                            'label' => $a->addon_name_snapshot,
                            'price' => (int) $a->addon_price_snapshot,
                        ];
                    });

                    $snapshotUnitPrice = (int) $item->price_snapshot;

                    // =========================
                    // LIVE DATA
                    // =========================
                    $liveUnitPrice = $variant ? (int) $variant?->price : $snapshotUnitPrice;
                    $liveStock = $variant ? (int) $variant?->stock : 0;

                    $product = $item->itemable;

                    $isPublic = $product
                        ? in_array($product->status, ['published', 'archived'])
                        : false;

                    /* =========================
                     * SNAPSHOT IMAGE (DARI STORAGE)
                     * ========================= */
                    $image = null;
                    if ($item->image_snapshot_path) {
                        $image = (object) [
                            'src_url' => URL::signedRoute(
                                'cart-snapshots.show',
                                ['cartItem' => $item->id],
                                now()->addMinutes(60),
                                true
                            ),
                        ];
                    }

                    /* =========================
                     * PRODUCT DETAIL IMAGES
                     * ========================= */
                    $product = $item->itemable;

                    if ($product) {

                        // cover image
                        if ($product->coverImage) {
                            $this->applyImageSrcUrl($product->coverImage, $isPublic);
                        }

                        // product images
                        if ($product->images) {
                            $product->images->transform(
                                fn($img) => $this->applyImageSrcUrl($img, $isPublic)
                            );
                        }

                        // option values images
                        if ($product->options) {
                            $product->options->transform(function ($option) use ($isPublic) {
                                if ($option->values) {
                                    $option->values->transform(function ($value) use ($isPublic) {
                                        if (!empty($value->image_path)) {
                                            $value->src_url = $isPublic
                                                ? route('images.product-option-value.show', ['optionValue' => $value->id])
                                                : URL::signedRoute(
                                                    'images.product-option-value.show',
                                                    ['optionValue' => $value->id],
                                                    now()->addMinutes(60)
                                                );
                                        } else {
                                            $value->src_url = null;
                                        }

                                        $value->makeHidden(['image_path', 'created_at', 'updated_at']);
                                        return $value;
                                    });
                                }
                                return $option;
                            });
                        }

                    }


                    $currentStock = $variant?->stock ?? 0;
                    $cartQty = $item->quantity;

                    $isOverStock = $cartQty > $currentStock;



                    return [
                        'cart_item_id' => $item->id,
                        'quantity' => $item->quantity,

                        // 🔒 SNAPSHOT
                        'snapshot' => [
                            'name' => $item->itemable_name_snapshot,
                            'variant_label' => $item->product_variant_name_snapshot,
                            'image' => $image,
                            'unit_price' => $snapshotUnitPrice,
                            'addons' => $snapshotAddons,
                            'addon_total_price' => (int) $snapshotAddons->sum('price'),
                        ],

                        // 🔥 LIVE
                        'live' => [
                            'unit_price' => $liveUnitPrice,
                            'max_stock' => $liveStock,
                            'is_available' => $variant !== null && $liveStock > 0,
                        ],

                        // 🚨 PERUBAHAN
                        'changes' => [
                            'price_changed' => $liveUnitPrice !== $snapshotUnitPrice,
                            'is_over_stock' => $isOverStock,
                        ],

                        // 🔧 UNTUK EDIT
                        'selected_configuration' => [
                            'product_id' => $item->itemable_id,
                            'variant_id' => $variant?->id,
                            'addon_ids' => $item->addons->map(fn($a) => [
                                'addon_group_id' => $a->addon_group_id,
                                'addon_id' => $a->addon_id,
                            ])->values(),
                        ],

                        // 📦 UNTUK MODAL EDIT
                        'product_details' => $item->itemable,
                    ];
                })->values(),


            ];
        });

        return ApiResponse::success(
            $cartStores,
            'Cart fetched'
        );
    }
    public function updateQuantity(Request $request, $id)
    {
        $cartItem = CartItem::where('id', $id)
            ->whereHas(
                'cart',
                fn($q) =>
                $q->where('user_id', Auth::id())
            )
            ->with(['variant', 'itemable', 'cart'])
            ->firstOrFail();

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $availableStock = $cartItem->variant
            ? $cartItem->variant->stock
            : ($cartItem->itemable->stock ?? 0);

        // ✅ Hitung total quantity dari SEMUA cart items dengan variant yang sama
        $otherItemsQty = $cartItem->cart->items()
            ->where('id', '!=', $cartItem->id) // exclude current item
            ->where('itemable_id', $cartItem->itemable_id)
            ->where('itemable_type', $cartItem->itemable_type)
            ->where('product_variant_id', $cartItem->product_variant_id)
            ->sum('quantity');

        $totalAfterUpdate = $otherItemsQty + $request->quantity;

        if ($totalAfterUpdate > $availableStock) {
            return ApiResponse::error(
                'Stok tidak mencukupi. Total di keranjang akan melebihi stok tersedia.',
                422
            );
        }

        $cartItem->update([
            'quantity' => $request->quantity,
        ]);

        return response()->json([
            'message' => 'Quantity updated',
            'cart_item_id' => $cartItem->id,
            'quantity' => $cartItem->quantity,
            'stock' => $availableStock,
        ]);
    }


    public function removeItem(int $id)
    {
        $cartItem = CartItem::where('id', $id)
            ->whereHas(
                'cart',
                fn($q) =>
                $q->where('user_id', Auth::id())
            )
            ->with('cart')
            ->firstOrFail();

        return DB::transaction(function () use ($cartItem) {
            $cart = $cartItem->cart;

            // Delete snapshot image from storage
            if ($cartItem->image_snapshot_path) {
                app(\App\Services\ImageOptimizationService::class)->deleteImages($cartItem->image_snapshot_path, 'public');
            }

            $cartItem->delete();

            if ($cart->items()->count() === 0) {
                $cart->delete();

                return ApiResponse::success(
                    [
                        'cart_deleted' => true,
                    ],
                    'Item removed and cart deleted'
                );
            }

            return ApiResponse::success(
                [
                    'cart_item_id' => $cartItem->id,
                    'cart_deleted' => false,
                ],
                'Item removed from cart'
            );
        });
    }


    public function addToCart(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',

            'addons' => 'nullable|array',
            'addons.*.group_id' => 'required|exists:addon_groups,id',
            'addons.*.addon_id' => 'required|exists:addons,id',
        ]);

        return DB::transaction(function () use ($request) {

            /** ===============================
             * 1. AMBIL PRODUCT (LIVE)
             * =============================== */
            $product = Product::with('merchant')->findOrFail($request->product_id);

            // Pencegahan: User tidak boleh membeli produk dari tokonya sendiri
            if ($product->merchant && $product->merchant->user_id === Auth::id()) {
                return ApiResponse::error('Anda tidak dapat membeli atau memasukkan produk dari toko Anda sendiri ke keranjang.', 403);
            }

            /** ===============================
             * 2. CART PER MERCHANT
             * =============================== */
            $cart = Cart::firstOrCreate([
                'user_id' => Auth::id(),
                'merchant_id' => $product->merchant_id,
            ]);

            /** ===============================
             * 3. NORMALISASI ADDONS (UNTUK DUPLIKASI)
             * =============================== */
            $addonSet = collect($request->addons ?? [])
                ->map(fn($a) => [
                    'addon_group_id' => (int) $a['group_id'],
                    'addon_id' => (int) $a['addon_id'],
                ])
                ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                ->values();

            /** ===============================
             * 4. CEK DUPLIKAT CART ITEM
             * =============================== */
            $existingItem = $cart->items()
                ->where('itemable_id', $product->id)
                ->where('itemable_type', Product::class)
                ->where('product_variant_id', $request->variant_id)
                ->get()
                ->first(function ($item) use ($addonSet) {
                    $existingAddonSet = $item->addons
                        ->map(fn($a) => [
                            'addon_group_id' => (int) $a->addon_group_id,
                            'addon_id' => (int) $a->addon_id,
                        ])
                        ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                        ->values();

                    return $existingAddonSet->toJson() === $addonSet->toJson();
                });

            /** ===============================
             * 5. AMBIL VARIANT (LIVE → SNAPSHOT)
             * =============================== */
            $variant = $request->variant_id
                ? ProductVariant::findOrFail($request->variant_id)
                : null;

            $variantLabel = $variant
                ? $variant->optionValues
                    ->map(fn($ov) => $ov->option->option_name . ': ' . $ov->option_value)
                    ->implode(', ')
                : null;
            /** ===============================
             * 5.5 VALIDASI STOCK
             * =============================== */

            // stok live
            $availableStock = $variant
                ? (int) $variant->stock
                : (int) ($product->stock ?? 0);

            // qty sudah ada di cart (SEMUA ITEM DENGAN VARIANT INI)
            $currentQtyInCart = $cart->items()
                ->where('itemable_id', $product->id)
                ->where('itemable_type', Product::class)
                ->where('product_variant_id', $variant?->id)
                ->sum('quantity');

            $requestedQty = (int) $request->quantity;
            $totalAfterAdd = $currentQtyInCart + $requestedQty;

            if ($totalAfterAdd > $availableStock) {
                return ApiResponse::error(
                    'Stok tidak mencukupi',
                    422
                );
            }

            if ($existingItem) {
                $existingItem->increment('quantity', $requestedQty);

                return ApiResponse::success(
                    [
                        'cart_item_id' => $existingItem->id,
                    ],
                    'Cart item quantity updated'
                );
            }


            /** ===============================
             * 6. CREATE CART ITEM (PURE SNAPSHOT)
             * =============================== */
            $cartItem = $cart->items()->create([
                'itemable_id' => $product->id,
                'itemable_type' => Product::class,

                // FK NON-RELASI (REFERENCE ONLY)
                'product_variant_id' => $variant?->id,

                // SNAPSHOT
                'itemable_name_snapshot' => $product->name,
                'product_variant_name_snapshot' => $variantLabel,
                'price_snapshot' => (int) ($variant?->price ?? $product->price),

                'quantity' => $request->quantity,
            ]);

            // Snapshot cover image to storage (if available)
            $snapshotPath = $this->snapshotCoverImage($product, $cartItem);
            if ($snapshotPath) {
                $cartItem->update(['image_snapshot_path' => $snapshotPath]);
            }

            /** ===============================
             * 7. SIMPAN ADDON SNAPSHOT
             * =============================== */
            if ($addonSet->isNotEmpty()) {
                $addonData = $addonSet->map(function ($a) {

                    $option = AddonGroupOption::with('addon')
                        ->where('addon_group_id', $a['addon_group_id'])
                        ->where('addon_id', $a['addon_id'])
                        ->firstOrFail();

                    return [
                        // reference only
                        'addon_group_id' => $a['addon_group_id'],
                        'addon_id' => $a['addon_id'],

                        // snapshot
                        'addon_name_snapshot' => $option->addon->addon_name,
                        'addon_price_snapshot' => (int) $option->addon_price,
                    ];
                });

                $cartItem->addons()->createMany($addonData->toArray());
            }

            return ApiResponse::success(
                [
                    'cart_item_id' => $cartItem->id,
                ],
                'Added to cart'
            );
        });
    }

    public function updateVariant(Request $request, int $cartItemId)
    {
        $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'addons' => 'nullable|array',
            'addons.*.addon_group_id' => 'required|exists:addon_groups,id',
            'addons.*.addon_id' => 'required|exists:addons,id',
        ]);

        $cartItem = CartItem::where('id', $cartItemId)
            ->whereHas(
                'cart',
                fn($q) =>
                $q->where('user_id', Auth::id())
            )
            ->with(['cart', 'addons'])
            ->firstOrFail();

        return DB::transaction(function () use ($request, $cartItem) {
            $variant = ProductVariant::where('id', $request->product_variant_id)
                ->where('product_id', $cartItem->itemable_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($variant->stock < $cartItem->quantity) {
                return response()->json([
                    'message' => 'Stok varian tidak mencukupi'
                ], 422);
            }

            $addonSet = collect($request->addons ?? [])
                ->map(fn($a) => [
                    'addon_group_id' => (int) $a['addon_group_id'],
                    'addon_id' => (int) $a['addon_id'],
                ])
                ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                ->values();

            $duplicateItem = $cartItem->cart->items()
                ->where('id', '!=', $cartItem->id)
                ->where('product_variant_id', $variant->id)
                ->with('addons')
                ->get()
                ->first(
                    fn($item) =>
                    $item->addons
                        ->map(fn($a) => [
                            'addon_group_id' => (int) $a->addon_group_id,
                            'addon_id' => (int) $a->addon_id,
                        ])
                        ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                        ->values()
                        ->toJson() === $addonSet->toJson()
                );

            if ($duplicateItem) {
                $duplicateItem->increment('quantity', $cartItem->quantity);
                $cartItem->addons()->delete();

                // Delete snapshot image from storage before deleting cart item
                if ($cartItem->image_snapshot_path) {
                    app(\App\Services\ImageOptimizationService::class)->deleteImages($cartItem->image_snapshot_path, 'public');
                }

                $cartItem->delete();

                return response()->json([
                    'message' => 'Item digabung',
                    'cart_item_id' => $duplicateItem->id,
                ]);
            }

            $cartItem->update([
                'product_variant_id' => $variant->id,
                'price_snapshot' => (int) $variant->price,
            ]);

            $cartItem->addons()->delete();

            if ($addonSet->isNotEmpty()) {
                $addonData = $addonSet->map(function ($a) {
                    $option = AddonGroupOption::with('addon')
                        ->where('addon_group_id', $a['addon_group_id'])
                        ->where('addon_id', $a['addon_id'])
                        ->firstOrFail();

                    return [
                        // reference
                        'addon_group_id' => $a['addon_group_id'],
                        'addon_id' => $a['addon_id'],

                        // SNAPSHOT (WAJIB SAMA)
                        'addon_name_snapshot' => $option->addon->addon_name,
                        'addon_price_snapshot' => (int) $option->addon_price, // ⬅️ INI YANG BENAR
                    ];
                });

                $cartItem->addons()->createMany($addonData->toArray());
            }

            return ApiResponse::success(
                [
                    'cart_item_id' => $cartItem->id,
                ],
                'Varian berhasil diperbarui'
            );
        });
    }

    public function clearCart(int $cartId)
    {
        $cart = Cart::where('id', $cartId)
            ->where('user_id', Auth::id())
            ->with('items')
            ->firstOrFail();

        DB::transaction(function () use ($cart) {
            // Delete all snapshot images from storage
            foreach ($cart->items as $item) {
                if ($item->image_snapshot_path) {
                    app(\App\Services\ImageOptimizationService::class)->deleteImages($item->image_snapshot_path, 'public');
                }
            }

            $cart->items()->delete();
            $cart->delete();
        });

        return ApiResponse::success(
            null,
            'Cart berhasil dikosongkan'
        );
    }



    public function count()
    {
        $userId = Auth::id();

        if (!$userId) {
            return ApiResponse::success(
                ['count' => 0],
                'Cart item count retrieved successfully.'
            );
        }

        $count = CartItem::query()
            ->whereHas('cart', fn($q) => $q->where('user_id', $userId))
            ->sum('quantity');

        return ApiResponse::success(
            ['count' => (int) ($count ?? 0)],
            'Cart item count retrieved successfully.'
        );
    }
}
