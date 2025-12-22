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
                            'label' => $a->addon->addon_name,
                            'price' => (int) $a->addon_price_snapshot,
                        ];
                    });

                    $snapshotUnitPrice = (int) $item->price_snapshot;

                    // =========================
                    // LIVE DATA
                    // =========================
                    $liveUnitPrice = $variant ? (int) $variant?->price : $snapshotUnitPrice;
                    $liveStock = $variant ? (int) $variant?->stock : 0;

                    $isPublic = in_array($item->itemable->status, ['published', 'archived']);

                    /* =========================
                     * SNAPSHOT IMAGE
                     * ========================= */
                    $image = $this->applyImageSrcUrl($item->imageSnapshot, $isPublic);

                    /* =========================
                     * PRODUCT DETAIL IMAGES
                     * ========================= */
                    $product = $item->itemable;

                    // cover image
                    if ($product->coverImage) {
                        $this->applyImageSrcUrl($product->coverImage, $isPublic);
                    }

                    // product images (jika dipakai di modal edit)
                    if ($product->images) {
                        $product->images->transform(
                            fn($img) =>
                            $this->applyImageSrcUrl($img, $isPublic)
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

        return response()->json([
            'success' => true,
            'data' => $cartStores
        ]);
    }
    public function updateQuantity(Request $request, $id)
    {
        $cartItem = CartItem::where('id', $id)
            ->whereHas(
                'cart',
                fn($q) =>
                $q->where('user_id', Auth::id())
            )
            ->with(['variant', 'itemable'])
            ->firstOrFail();

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $availableStock = $cartItem->variant
            ? $cartItem->variant->stock
            : ($cartItem->itemable->stock ?? 0);

        if ($request->quantity > $availableStock) {
            return response()->json([
                'message' => 'Stock tidak mencukupi',
                'available_stock' => $availableStock,
            ], 422);
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

            $cartItem->delete();

            if ($cart->items()->count() === 0) {
                $cart->delete();

                return response()->json([
                    'message' => 'Item removed and cart deleted',
                    'cart_deleted' => true,
                ]);
            }

            return response()->json([
                'message' => 'Item removed from cart',
                'cart_item_id' => $cartItem->id,
                'cart_deleted' => false,
            ]);
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
            $product = Product::findOrFail($request->product_id);

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
                return response()->json([
                    'message' => 'Stok tidak mencukupi',
                    'available_stock' => $availableStock,
                    'current_in_cart' => $currentQtyInCart,
                    'requested' => $requestedQty,
                ], 422);
            }

            if ($existingItem) {
                $existingItem->increment('quantity', $requestedQty);

                return response()->json([
                    'message' => 'Cart item quantity updated',
                    'cart_item_id' => $existingItem->id,
                ]);
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

            return response()->json([
                'message' => 'Added to cart',
                'cart_item_id' => $cartItem->id,
            ]);
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

            return response()->json([
                'message' => 'Varian berhasil diperbarui',
                'cart_item_id' => $cartItem->id,
            ]);
        });
    }

    public function clearCart(int $cartId)
    {
        $cart = Cart::where('id', $cartId)
            ->where('user_id', Auth::id())
            ->with('items')
            ->firstOrFail();

        DB::transaction(function () use ($cart) {
            $cart->items()->delete();
            $cart->delete();
        });

        return response()->json([
            'message' => 'Cart berhasil dikosongkan',
        ]);
    }



    public function count()
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'count' => 0,
            ]);
        }

        $count = Cart::where('user_id', $userId)
            ->withSum('items as total_quantity', 'quantity')
            ->value('total_quantity');

        return response()->json([
            'count' => (int) ($count ?? 0),
        ]);
    }
}
