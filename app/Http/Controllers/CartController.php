<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\CartItem;
use Illuminate\Http\Request;
use App\Models\ProductVariant;
use App\Models\AddonGroupOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        // 1. Ambil Cart dengan Eager Loading Super Lengkap
        // Kita load data "Selected" (Variant/Addon yg dipilih)
        // DAN data "Master" (Product options/variants/addons lengkap untuk fitur edit)
        $carts = Cart::with([
            'merchant.addresses.district.city.province', // Alamat Merchant Lengkap

            // --- DATA YANG DIPILIH (SELECTED) ---
            'items.variant.optionValues.option', // Varian yang sedang dipilih (misal: Merah, XL)
            'items.addons.addon',
            'items.addons.addonGroupOption',             // Addon yang sedang dipilih (misal: Keju)

            // --- DATA MASTER PRODUK (FULL CONTEXT UNTUK EDIT) ---
            'items.itemable' => function ($q) {
                // Ini meniru logic dari ProductController@show
                $q->with([
                    'coverImage',
                    'images' => fn($iq) => $iq->orderBy('display_order'),
                    'categories',
                    'options' => function ($oq) {
                    $oq->with(['values' => fn($vq) => $vq->select('id', 'product_option_id', 'option_value', 'image_path')])
                        ->select('id', 'product_id', 'option_name', 'uses_image')
                        ->orderBy('id');
                },
                    // Load semua kombinasi stock/harga agar bisa ganti varian di cart
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
                    // Load semua grup addon agar bisa tambah/kurang addon di cart
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

        // 2. Mapping Data
        $cartStores = $carts->map(function ($cart) {
            // Format Alamat Merchant
            $address = $cart->merchant->addresses->first();
            $fullAddress = null;
            if ($address) {
                $parts = [];
                if ($address->detail) {
                    $parts[] = $address->detail;
                }
                if ($address->district?->name) {
                    $parts[] = $address->district->name;
                }
                if ($address->city?->name) {
                    $parts[] = $address->city->name;
                }
                if ($address->province?->name) {
                    $parts[] = $address->province->name;
                }
                $fullAddress = implode(', ', $parts);
            }

            return [
                'cart_id' => $cart->id,
                'merchant' => [
                    'id' => $cart->merchant->id,
                    'name' => $cart->merchant->name,
                    'phone' => $cart->merchant->phone,
                    'address' => $fullAddress,
                    'logo' => $cart->merchant->logo_url ?? null, // Asumsi ada accessor/kolom logo
                ],
                'items' => $cart->items->map(function ($item) {
                    $product = $item->itemable;
                    $selectedVariant = $item->variant;

                    $basePrice = $selectedVariant
                        ? (int) $selectedVariant->price
                        : (int) $product->price;

                    // Gambar Prioritas: Varian > Cover > First Image > Placeholder
                    $displayImage = null;

                    // 1. Cek gambar spesifik varian (dari option value)
                    if ($selectedVariant && $selectedVariant->optionValues->isNotEmpty()) {
                        foreach ($selectedVariant->optionValues as $ov) {
                            if ($ov->image_path) {
                                $displayImage = asset('storage/' . $ov->image_path);
                                break;
                            }
                        }
                    }
                    // 2. Cek cover image produk
                    if (!$displayImage && $product->coverImage) {
                        $displayImage = asset('storage/' . $product->coverImage->image_path);
                    }
                    // 3. Fallback gambar pertama
                    if (!$displayImage && $product->images->isNotEmpty()) {
                        $displayImage = asset('storage/' . $product->images->first()->image_path);
                    }
                    // 4. Placeholder
                    if (!$displayImage) {
                        $displayImage = asset('images/placeholder-product.png');
                    }

                    $variantString = $selectedVariant
                        ? $selectedVariant->optionValues->pluck('option_value')->implode(', ')
                        : null;
                    // --- LABEL ADDON (Untuk Tampilan Ringkas) ---
                    $addons = $item->addons->map(function ($cartAddon) use ($product) {

                        $price = 0;

                        foreach ($product->addonGroups as $group) {
                            if ($group->id !== $cartAddon->addon_group_id) {
                                continue;
                            }

                            $option = $group->options
                                ->firstWhere('addon_id', $cartAddon->addon_id);

                            if ($option) {
                                $price = (int) $option->addon_price;
                                break;
                            }
                        }

                        return [
                            'label' => $cartAddon->addon->addon_name,
                            'price' => $price,
                        ];
                    })->values();

                    $addonTotal = $addons->sum('price');


                    return [
                        // Identitas Item di Cart
                        'cart_item_id' => $item->id,
                        'quantity' => $item->quantity,
                        // Data Tampilan (Snapshot)
                        'display' => [
                            'name' => $product->name,
                            'slug' => $product->slug,
                            'image' => $displayImage,
                            'unit_price' => (int) $basePrice, // Harga dasar sebelum addon
                            'variant_label' => $variantString, // String "Merah, XL"
                            'addons' => $addons,
                            'addon_total_price' => (int) $addonTotal,
                            'max_stock' => $selectedVariant
                                ? (int) $selectedVariant->stock
                                : (int) $product->stock,
                        ],

                        // Data Seleksi (ID untuk Logic Frontend)
                        'selected_configuration' => [
                            'product_id' => $product->id,
                            'variant_id' => $selectedVariant?->id, // ID Kombinasi SKU saat ini
                            'addon_ids' => $item->addons->map(fn($a) => [
                                'addon_group_id' => $a->addon_group_id,
                                'addon_id' => $a->addon_id,
                            ])->values(),
                        ],

                        // --- DATA LENGKAP PRODUK (FULL CONTEXT) ---
                        // Ini dikirim agar Frontend bisa membuka modal edit tanpa fetch lagi
                        'product_details' => $product,
                    ];
                }),
            ];
        })->values(); // Reset keys agar jadi array JSON standar

        return response()->json([
            'success' => true,
            'data' => $cartStores
        ]);
    }
    public function updateQuantity(Request $request, CartItem $cartItem)
    {
        // 🔒 pastikan item milik user
        if ($cartItem->cart->user_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $variant = $cartItem->variant;
        $product = $cartItem->itemable;

        // 🧮 cek stock (LIVE)
        $availableStock = $variant
            ? $variant->stock
            : ($product->stock ?? 0);

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

    public function removeItem(CartItem $cartItem)
    {
        // 🔒 pastikan item milik user
        abort_if($cartItem->cart->user_id !== Auth::id(), 403, 'Unauthorized');

        return DB::transaction(function () use ($cartItem) {

            $cart = $cartItem->cart;

            // hapus cart item
            $cartItem->delete();

            // 🔥 jika ini item terakhir → hapus cart
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
            $product = Product::findOrFail($request->product_id);

            // 1. Ambil / Buat Cart
            $cart = Cart::firstOrCreate([
                'user_id' => Auth::id(),
                'merchant_id' => $product->merchant_id,
            ]);

            // 2. Siapkan Data Addon (Hanya ID)
            // Sort agar urutan array [1, 2] dianggap sama dengan [2, 1] saat cek duplikat
            $addonSet = collect($request->addons ?? [])
                ->map(fn($a) => [
                    'addon_group_id' => (int) $a['group_id'],
                    'addon_id' => (int) $a['addon_id'],
                ])
                ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                ->values();

            // 3. Cek Duplikat Item (Item sama + Varian sama + Addons persis sama)
            $existingItem = $cart->items()
                ->where('itemable_id', $product->id)
                ->where('itemable_type', Product::class)
                ->where('product_variant_id', $request->variant_id) // Cek Varian
                ->with('addons')
                ->get()
                ->first(function ($item) use ($addonSet) {
                    // Bandingkan addons yang ada di DB dengan request baru
                    $existingAddonSet = $item->addons
                        ->map(fn($a) => [
                            'addon_group_id' => (int) $a->addon_group_id,
                            'addon_id' => (int) $a->addon_id,
                        ])
                        ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                        ->values();

                    return $existingAddonSet->toJson() === $addonSet->toJson();
                });

            // 4. Jika Duplikat -> Tambah Quantity Saja
            if ($existingItem) {
                $existingItem->increment('quantity', $request->quantity);
                return response()->json([
                    'message' => 'Cart item quantity updated',
                    'cart_item_id' => $existingItem->id,
                ]);
            }

            // 5. Jika Baru -> Buat Item & Simpan Addons
            $item = $cart->items()->create([
                'itemable_id' => $product->id,
                'itemable_type' => Product::class,
                'product_variant_id' => $request->variant_id, // Pastikan ini masuk ke DB
                'quantity' => $request->quantity,
            ]);

            // Simpan Addons ke tabel cart_item_addons
            if ($addonSet->isNotEmpty()) {
                // createMany akan otomatis mengisi 'cart_item_id'
                $item->addons()->createMany($addonSet->toArray());
            }

            return response()->json([
                'message' => 'Added to cart',
                'cart_item_id' => $item->id,
            ]);
        });
    }

    public function updateVariant(Request $request, CartItem $cartItem)
    {
        $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'addons' => 'nullable|array',
            'addons.*.addon_group_id' => 'required|exists:addon_groups,id',
            'addons.*.addon_id' => 'required|exists:addons,id',
        ]);

        // 🔐 Security: pastikan cart milik user
        abort_if($cartItem->cart->user_id !== Auth::id(), 403);

        return DB::transaction(function () use ($request, $cartItem) {

            /* ===============================
             * 1. VALIDASI VARIANT
             * =============================== */
            $variant = ProductVariant::where('id', $request->product_variant_id)
                ->where('product_id', $cartItem->itemable_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($variant->stock < $cartItem->quantity) {
                return response()->json([
                    'message' => 'Stok varian tidak mencukupi'
                ], 422);
            }

            /* ===============================
             * 2. NORMALISASI ADDONS
             * =============================== */
            $addonSet = collect($request->addons ?? [])
                ->map(fn($a) => [
                    'addon_group_id' => (int) $a['addon_group_id'],
                    'addon_id' => (int) $a['addon_id'],
                ])
                ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                ->values();

            /* ===============================
             * 3. CEK DUPLIKAT ITEM
             * =============================== */
            $duplicateItem = $cartItem->cart->items()
                ->where('id', '!=', $cartItem->id)
                ->where('itemable_id', $cartItem->itemable_id)
                ->where('itemable_type', $cartItem->itemable_type)
                ->where('product_variant_id', $variant->id)
                ->with('addons')
                ->get()
                ->first(function ($item) use ($addonSet) {
                    $existing = $item->addons
                        ->map(fn($a) => [
                            'addon_group_id' => (int) $a->addon_group_id,
                            'addon_id' => (int) $a->addon_id,
                        ])
                        ->sortBy(fn($a) => $a['addon_group_id'] . '-' . $a['addon_id'])
                        ->values();

                    return $existing->toJson() === $addonSet->toJson();
                });

            /* ===============================
             * 4. JIKA DUPLIKAT → MERGE
             * =============================== */
            if ($duplicateItem) {
                $duplicateItem->increment('quantity', $cartItem->quantity);

                // hapus item lama
                $cartItem->addons()->delete();
                $cartItem->delete();

                return response()->json([
                    'message' => 'Item digabung dengan item yang sudah ada',
                    'cart_item_id' => $duplicateItem->id,
                ]);
            }

            /* ===============================
             * 5. UPDATE VARIANT
             * =============================== */
            $cartItem->update([
                'product_variant_id' => $variant->id,
            ]);

            /* ===============================
             * 6. UPDATE ADDONS
             * =============================== */
            $cartItem->addons()->delete();

            if ($addonSet->isNotEmpty()) {
                $addonData = $addonSet->map(function ($a) {
                    $option = AddonGroupOption::where('addon_group_id', $a['addon_group_id'])
                        ->where('addon_id', $a['addon_id'])
                        ->firstOrFail();

                    return [
                        'addon_group_id' => $a['addon_group_id'],
                        'addon_id' => $a['addon_id'],
                        'price' => $option->addon_price,
                    ];
                });

                $cartItem->addons()->createMany($addonData->toArray());
            }

            return response()->json([
                'message' => 'Varian & addon berhasil diperbarui',
                'cart_item_id' => $cartItem->id,
            ]);
        });
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
