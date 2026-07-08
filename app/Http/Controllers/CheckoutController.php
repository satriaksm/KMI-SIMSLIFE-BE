<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ProductOrderItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CheckoutController extends Controller
{
    public function confirmWhatsappOrder(Request $request)
    {
        $data = $request->validate([
            'merchant_slug' => 'required|string',
            'mode' => 'required|in:product,cart',
            'shipping_method' => 'required|in:pickup,delivery',
            'payment_method' => 'required|in:COD,QRIS',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'delivery_address' => 'nullable|string',
            'delivery_note' => 'nullable|string',
            'product_note' => 'nullable|string',
            'voucher_code' => 'nullable|string|max:100',
            'shipping_fee' => 'nullable|integer|min:0',
            'cart_item_ids' => 'nullable|array',
            'cart_item_ids.*' => 'integer|exists:cart_items,id',
            'product_slug' => 'nullable|string',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1',
            'addons' => 'nullable|array',
            'addons.*.id' => 'nullable',
            'addons.*.name' => 'nullable|string',
            'addons.*.price' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('Silakan login terlebih dahulu', 401);
        }

        $merchant = Merchant::query()
            ->where('slug', $data['merchant_slug'])
            ->first();

        if (!$merchant) {
            return ApiResponse::error('Merchant tidak ditemukan', 404);
        }

        $mode = $data['mode'];
        $shippingMethod = $data['shipping_method'];
        $paymentMethod = $data['payment_method'];
        $voucherCode = trim((string) ($data['voucher_code'] ?? ''));
        $shippingFee = (int) ($data['shipping_fee'] ?? 0);
        $cartItemIds = collect($data['cart_item_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
        $now = now();

        try {
            $result = DB::transaction(function () use (
                $user,
                $merchant,
                $data,
                $mode,
                $shippingMethod,
                $paymentMethod,
                $voucherCode,
                $shippingFee,
                $cartItemIds,
                $now
            ) {
                $fail = function (string $message, int $status = 422): never {
                    throw new \RuntimeException($message, $status);
                };

                $orderItems = [];
                $grossSubtotal = 0;

                if ($mode === 'cart') {
                    if ($cartItemIds->isEmpty()) {
                        $fail('Item keranjang tidak ditemukan', 422);
                    }

                    $cartItems = CartItem::query()
                        ->whereIn('id', $cartItemIds)
                        ->whereHas('cart', function ($q) use ($user, $merchant) {
                            $q->where('user_id', $user->id)
                                ->where('merchant_id', $merchant->id);
                        })
                        ->with(['addons', 'variant'])
                        ->lockForUpdate()
                        ->get();

                    if ($cartItems->count() !== $cartItemIds->count()) {
                        $fail('Sebagian item keranjang tidak valid', 422);
                    }

                    foreach ($cartItems as $cartItem) {
                        $product = Product::query()
                            ->where('id', $cartItem->itemable_id)
                            ->where('merchant_id', $merchant->id)
                            ->lockForUpdate()
                            ->first();

                        if (!$product) {
                            $fail('Produk tidak ditemukan', 404);
                        }

                        $variant = $cartItem->product_variant_id
                            ? ProductVariant::query()
                                ->where('id', $cartItem->product_variant_id)
                                ->where('product_id', $product->id)
                                ->lockForUpdate()
                                ->first()
                            : null;

                        if (!$variant) {
                            $fail('Varian produk tidak valid untuk checkout ini', 422);
                        }

                        $quantity = (int) $cartItem->quantity;
                        if ((int) $variant->stock < $quantity) {
                            $fail("Stok tidak mencukupi untuk {$product->name}", 422);
                        }

                        $unitPrice = (int) $variant->price;
                        $addonTotal = (int) $cartItem->addons->sum('addon_price_snapshot');
                        $lineSubtotal = ($unitPrice + $addonTotal) * $quantity;

                        $orderItems[] = [
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'quantity' => $quantity,
                            'price' => $unitPrice + $addonTotal,
                            'subtotal' => $lineSubtotal,
                            'cart_item' => $cartItem,
                            'variant' => $variant,
                        ];

                        $grossSubtotal += $lineSubtotal;
                    }
                } else {
                    $product = Product::query()
                        ->where('slug', $data['product_slug'] ?? '')
                        ->where('merchant_id', $merchant->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$product) {
                        $fail('Produk tidak ditemukan', 404);
                    }

                    $variantId = (int) ($data['product_variant_id'] ?? 0);
                    if ($variantId <= 0) {
                        $fail('Varian produk wajib dipilih', 422);
                    }

                    $variant = ProductVariant::query()
                        ->where('id', $variantId)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$variant) {
                        $fail('Varian produk tidak ditemukan', 404);
                    }

                    $quantity = max(1, (int) ($data['quantity'] ?? 1));
                    if ((int) $variant->stock < $quantity) {
                        $fail('Stok varian tidak mencukupi', 422);
                    }

                    $addons = collect($data['addons'] ?? []);
                    $addonTotal = (int) $addons->sum(fn ($addon) => (int) ($addon['price'] ?? 0));
                    $lineSubtotal = ((int) $variant->price + $addonTotal) * $quantity;

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => $quantity,
                        'price' => (int) $variant->price + $addonTotal,
                        'subtotal' => $lineSubtotal,
                        'variant' => $variant,
                    ];

                    $grossSubtotal += $lineSubtotal;
                }

                $voucher = null;
                $discountAmount = 0;

                if ($voucherCode !== '') {
                    $acceptedEventIds = DB::table('event_merchants')
                        ->select('event_id')
                        ->where('merchant_id', $merchant->id)
                        ->where('status', 'accepted');

                    $voucher = Voucher::query()
                        ->where('voucher_code', $voucherCode)
                        ->where(function ($q) use ($merchant, $acceptedEventIds) {
                            $q->where('merchant_id', $merchant->id)
                                ->orWhereIn('event_id', $acceptedEventIds);
                        })
                        ->active()
                        ->lockForUpdate()
                        ->first();

                    if (!$voucher) {
                        $fail('Voucher tidak valid atau sudah tidak aktif', 422);
                    }

                    if ($grossSubtotal < (int) $voucher->min_purchase_amount) {
                        $fail('Nilai belanja belum memenuhi minimum voucher', 422);
                    }

                    $totalUsed = VoucherUsage::query()
                        ->where('voucher_id', $voucher->id)
                        ->lockForUpdate()
                        ->get()
                        ->count();

                    if ($voucher->usage_limit !== null && $totalUsed >= (int) $voucher->usage_limit) {
                        $fail('Kuota voucher sudah habis', 422);
                    }

                    $userUsed = VoucherUsage::query()
                        ->where('voucher_id', $voucher->id)
                        ->where('user_id', $user->id)
                        ->lockForUpdate()
                        ->get()
                        ->count();

                    if (
                        $voucher->usage_limit_per_user !== null &&
                        $userUsed >= (int) $voucher->usage_limit_per_user
                    ) {
                        $fail('Voucher sudah mencapai batas pemakaian per user', 422);
                    }

                    if ($voucher->voucher_type === 'fixed') {
                        $discountAmount = min((int) $voucher->value, $grossSubtotal);
                    } elseif ($voucher->voucher_type === 'percent') {
                        $discountAmount = (int) floor(($grossSubtotal * (float) $voucher->value) / 100);

                        if ($voucher->max_discount_amount) {
                            $discountAmount = min($discountAmount, (int) $voucher->max_discount_amount);
                        }
                    }
                }

                $finalTotal = max(0, $grossSubtotal + $shippingFee - $discountAmount);
                $pickupAddress = trim((string) ($merchant->address ?? ''));
                $deliveryAddress = trim((string) ($data['delivery_address'] ?? ''));
                $orderAddress = $shippingMethod === 'delivery'
                    ? ($deliveryAddress !== '' ? $deliveryAddress : $pickupAddress)
                    : ($pickupAddress !== '' ? $pickupAddress : $deliveryAddress);

                $order = Order::create([
                    'user_id' => $user->id,
                    'merchant_id' => $merchant->id,
                    'order_type' => 'product',
                    // NOTE: jasa_id is NO_LONGER accepted - use product_order_items instead
                    'nama' => $data['customer_name'],
                    'tel' => $data['customer_phone'],
                    'alamat' => $orderAddress,
                    'catatan' => $data['product_note'] ?? null,
                    'catatan_alamat' => $data['delivery_note'] ?? null,
                    'tanggal' => $now->toDateString(),
                    'waktu' => $now->format('H:i'),
                    'metode_pembayaran' => $paymentMethod,
                    'payment_method' => $paymentMethod,
                    'payment_status' => 'unpaid',
                    'promo_code' => $voucher?->voucher_code,
                    'total' => $finalTotal,
                    'status' => 'pending',
                ]);

                foreach ($orderItems as $item) {
                    ProductOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'subtotal' => $item['subtotal'],
                        'note' => $data['product_note'] ?? null,
                    ]);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'subtotal' => $item['subtotal'],
                    ]);

                    $variant = $item['variant'];
                    $variant->decrement('stock', $item['quantity']);
                }

                if ($voucher) {
                    VoucherUsage::create([
                        'user_id' => $user->id,
                        'voucher_id' => $voucher->id,
                        'order_id' => $order->id,
                        'discount_amount' => $discountAmount,
                    ]);
                }

                if ($mode === 'cart') {
                    $cartItems = collect($orderItems)->pluck('cart_item')->filter();
                    $cartIds = $cartItems
                        ->map(fn ($cartItem) => $cartItem->cart_id)
                        ->unique()
                        ->values();

                    foreach ($cartItems as $cartItem) {
                        if ($cartItem->image_snapshot_path) {
                            Storage::disk('public')->delete($cartItem->image_snapshot_path);
                        }

                        $cartItem->addons()->delete();
                        $cartItem->delete();
                    }

                    foreach ($cartIds as $cartId) {
                        $cartIsEmpty = CartItem::query()
                            ->where('cart_id', $cartId)
                            ->exists();

                        if ($cartIsEmpty) {
                            continue;
                        }

                        DB::table('carts')->where('id', $cartId)->delete();
                    }
                }

                return ApiResponse::success([
                    'order_id' => $order->id,
                    'merchant_slug' => $merchant->slug,
                    'mode' => $mode,
                    'subtotal' => $grossSubtotal,
                    'discount_amount' => $discountAmount,
                    'shipping_fee' => $shippingFee,
                    'total' => $finalTotal,
                    'voucher_code' => $voucher?->voucher_code,
                    'item_count' => count($orderItems),
                ], 'Checkout berhasil diproses');
            });

            return $result;
        } catch (\Throwable $e) {
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            return ApiResponse::error(
                $status === 500 ? 'Gagal memproses checkout' : $e->getMessage(),
                $status,
                ['error' => $e->getMessage()]
            );
        }
    }
}