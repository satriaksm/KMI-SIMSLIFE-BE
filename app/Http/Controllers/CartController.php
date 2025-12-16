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
    public function index()
    {
        $userId = Auth::id();

        // 1. Ambil Cart (Query tetap sama seperti sebelumnya)
        $carts = Cart::with([
            'merchant.addresses.district.city.province',
            'items.variant.optionValues.option',
            'items.addons.addon',
            'items.addons.addonGroupOption',
            'items.itemable' => function ($q) {
                $q->with([
                    'coverImage' => fn($ciq) => $ciq->select('id', 'imageable_id', 'imageable_type', ),
                    'images' => fn($iq) => $iq->Select('id', 'imageable_id', 'imageable_type', )
                        ->orderBy('display_order'),
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
                    $product = $item->itemable;

                    // ==========================================================
                    // START: LOGIC TRANSFORMASI PRODUCT (Sama seperti ProductController)
                    // ==========================================================
    
                    $isPublic = in_array($product->status, ['published', 'archived']);

                    // 1. Cover Image
                    if ($product->coverImage) {
                        $product->coverImage->src_url = $isPublic
                            ? route('images.show', ['image' => $product->coverImage->id])
                            : URL::signedRoute('images.show', ['image' => $product->coverImage->id], now()->addMinutes(60));
                        $product->coverImage->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                    }

                    // 2. Images Gallery
                    if ($product->images) {
                        $product->images->transform(function ($img) use ($isPublic) {
                            $img->src_url = $isPublic
                                ? route('images.show', ['image' => $img->id])
                                : URL::signedRoute('images.show', ['image' => $img->id], now()->addMinutes(60));
                            $img->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                            return $img;
                        });
                    }

                    // 3. Option Values Images
                    if ($product->options) {
                        $product->options->transform(function ($opt) use ($isPublic) {
                            if ($opt->values) {
                                $opt->values->transform(function ($val) use ($isPublic) {
                                    if (!empty($val->image_path)) {
                                        $val->src_url = $isPublic
                                            ? route('images.product-option-value.show', ['optionValue' => $val->id])
                                            : URL::signedRoute('images.product-option-value.show', ['optionValue' => $val->id], now()->addMinutes(60));
                                    } else {
                                        $val->src_url = null;
                                    }
                                    $val->makeHidden(['image_path', 'created_at', 'updated_at', 'pivot']);
                                    return $val;
                                });
                            }
                            $opt->makeHidden(['created_at', 'updated_at']);
                            return $opt;
                        });
                    }

                    // 4. Variants Cleaning
                    if ($product->variants) {
                        $product->variants->transform(function ($var) {
                            $var->makeHidden(['display_image', 'created_at', 'updated_at']);
                            if ($var->optionValues) {
                                $var->optionValues->transform(function ($ov) {
                                    $ov->makeHidden(['pivot', 'image_url', 'created_at', 'updated_at']);
                                    return $ov;
                                });
                            }
                            return $var;
                        });
                    }

                    // 5. Addons Cleaning
                    if ($product->addonGroups) {
                        $product->addonGroups->transform(function ($grp) {
                            $grp->makeHidden(['created_at', 'updated_at']);
                            if ($grp->options) {
                                $grp->options->transform(function ($opt) {
                                    $opt->makeHidden(['created_at', 'updated_at']);
                                    if ($opt->addon)
                                        $opt->addon->makeHidden(['created_at', 'updated_at']);
                                    return $opt;
                                });
                            }
                            return $grp;
                        });
                    }

                    // 6. Clean Product Categories & Product itself
                    if ($product->categories)
                        $product->categories->makeHidden(['pivot', 'created_at', 'updated_at']);
                    $product->makeHidden(['created_at', 'updated_at', 'images']); // Sembunyikan images raw jika mau
    
                    // ==========================================================
                    // END: LOGIC TRANSFORMASI
                    // ==========================================================
    

                    // --- LOGIC DISPLAY ITEM ---
                    $selectedVariant = $item->variant;
                    $basePrice = $selectedVariant ? (int) $selectedVariant->price : (int) $product->price;

                    // Tentukan Gambar Tampilan (Menggunakan src_url yang baru digenerate)
                    $displayImage = null;

                    // 1. Cek gambar spesifik varian (dari option value)
                    if ($selectedVariant && $selectedVariant->optionValues->isNotEmpty()) {
                        foreach ($selectedVariant->optionValues as $ov) {
                            // Kita cari OptionValue yang sesuai di dalam $product->options yang sudah di-transform
                            // agar mendapatkan src_url yang valid
                            $matchedOption = $product->options
                                ->where('id', $ov->product_option_id)->first();

                            if ($matchedOption) {
                                $matchedValue = $matchedOption->values->where('id', $ov->id)->first();
                                if ($matchedValue && $matchedValue->src_url) {
                                    $displayImage = $matchedValue->src_url;
                                    break;
                                }
                            }
                        }
                    }

                    // 2. Cek cover image produk
                    if (!$displayImage && $product->coverImage) {
                        $displayImage = $product->coverImage->src_url;
                    }

                    // 3. Fallback gambar pertama
                    if (!$displayImage && $product->images && $product->images->isNotEmpty()) {
                        $displayImage = $product->images->first()->src_url;
                    }

                    // 4. Placeholder
                    if (!$displayImage) {
                        $displayImage = asset('images/placeholder-product.png'); // Atau null
                    }

                    // String Varian
                    $variantString = $selectedVariant
                        ? $selectedVariant->optionValues->pluck('option_value')->implode(', ')
                        : null;

                    // Addons Display
                    $addons = $item->addons->map(function ($cartAddon) use ($product) {
                        $price = 0;
                        // Logic cari harga addon (sama seperti sebelumnya)
                        foreach ($product->addonGroups as $group) {
                            if ($group->id !== $cartAddon->addon_group_id)
                                continue;
                            $option = $group->options->firstWhere('addon_id', $cartAddon->addon_id);
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

                    return [
                        'cart_item_id' => $item->id,
                        'quantity' => $item->quantity,
                        'display' => [
                            'name' => $product->name,
                            'slug' => $product->slug,
                            'image' => $displayImage, // ✅ Sudah berupa URL (Signed/Public)
                            'unit_price' => (int) $basePrice,
                            'variant_label' => $variantString,
                            'addons' => $addons,
                            'addon_total_price' => (int) $addons->sum('price'),
                            'max_stock' => $selectedVariant ? (int) $selectedVariant->stock : (int) $product->total_stock ?? 0,
                        ],
                        'selected_configuration' => [
                            'product_id' => $product->id,
                            'variant_id' => $selectedVariant?->id,
                            'addon_ids' => $item->addons->map(fn($a) => [
                                'addon_group_id' => $a->addon_group_id,
                                'addon_id' => $a->addon_id,
                            ])->values(),
                        ],
                        // Data Lengkap untuk Edit (Sudah bersih dari timestamps & ada src_url)
                        'product_details' => $product,
                    ];
                }),
            ];
        })->values();

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
