<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOrderItem;
use App\Models\ProductOrderItemAddon;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Midtrans\Config as MidtransConfig;
use Midtrans\Snap;

class OrderController extends Controller
{
    public function customerIndex(Request $request)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with([
                'merchant',
                'items.addons.addon',
            ])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $orders = $query->paginate($perPage)->appends($request->query());

        return ApiResponse::success(
            $orders->items(),
            'Orders fetched',
            200,
            [
                'pagination' => [
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'next_page_url' => $orders->nextPageUrl(),
                    'prev_page_url' => $orders->previousPageUrl(),
                ],
            ]
        );
    }

    public function customerShow(Order $order)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $order->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        return ApiResponse::success(
            $order->load([
                'merchant',
                'items.product',
                'items.variant',
                'items.addons.addon',
            ]),
            'Order fetched'
        );
    }

    public function cancel(Order $order)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $order->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        if (in_array($order->status, ['paid', 'delivered', 'completed', 'cancelled'], true)) {
            return ApiResponse::error('Order tidak bisa dibatalkan', 422);
        }

        $order->status = 'cancelled';
        $order->cancelled_at = now();
        $order->save();

        return ApiResponse::success(
            $order->load(['items.addons.addon']),
            'Order cancelled'
        );
    }

    public function merchantIndex(Request $request, Merchant $merchant)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with([
                'items.addons.addon',
            ])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $orders = $query->paginate($perPage)->appends($request->query());

        return ApiResponse::success(
            $orders->items(),
            'Merchant orders fetched',
            200,
            [
                'pagination' => [
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'next_page_url' => $orders->nextPageUrl(),
                    'prev_page_url' => $orders->previousPageUrl(),
                ],
            ]
        );
    }

    public function merchantShow(Merchant $merchant, Order $order)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        if ((int) $order->merchant_id !== (int) $merchant->id) {
            return ApiResponse::error('Order tidak ditemukan', 404);
        }

        return ApiResponse::success(
            $order->load([
                'items.product',
                'items.variant',
                'items.addons.addon',
            ]),
            'Order fetched'
        );
    }

    public function updateStatus(Request $request, Merchant $merchant, Order $order)
    {
        $request->validate([
            'status' => 'required|in:responsed,delivered,completed,cancelled',
        ]);

        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        if ((int) $order->merchant_id !== (int) $merchant->id) {
            return ApiResponse::error('Order tidak ditemukan', 404);
        }

        $newStatus = (string) $request->input('status');

        $allowed = match ($newStatus) {
            'responsed' => in_array($order->status, ['pending'], true),
            'delivered' => in_array($order->status, ['paid', 'responsed'], true),
            'completed' => in_array($order->status, ['delivered'], true),
            'cancelled' => in_array($order->status, ['pending', 'responsed'], true),
            default => false,
        };

        if (!$allowed) {
            return ApiResponse::error('Perubahan status tidak valid', 422);
        }

        $order->status = $newStatus;
        if ($newStatus === 'responsed') {
            $order->responsed_at = now();
        } elseif ($newStatus === 'delivered') {
            $order->delivered_at = now();
        } elseif ($newStatus === 'completed') {
            $order->completed_at = now();
        } elseif ($newStatus === 'cancelled') {
            $order->cancelled_at = now();
        }
        $order->save();

        return ApiResponse::success(
            $order->load(['items.addons.addon']),
            'Order status updated'
        );
    }

    public function checkoutProductFromCart(Request $request)
    {
        $request->validate([
            'cart_id' => 'required|integer|exists:carts,id',
            'address_id' => 'nullable|integer|exists:addresses,id',
            'voucher_id' => 'nullable|integer|exists:vouchers,id',
        ]);

        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $cart = Cart::query()
            ->where('id', $request->integer('cart_id'))
            ->where('user_id', $user->id)
            ->with([
                'merchant',
                'items.addons',
                'items.itemable',
                'items.variant',
            ])
            ->firstOrFail();

        if ($cart->items->isEmpty()) {
            return ApiResponse::error('Cart kosong', 422);
        }

        foreach ($cart->items as $cartItem) {
            if ($cartItem->itemable_type !== Product::class) {
                return ApiResponse::error('Checkout product hanya mendukung item product', 422);
            }
        }

        if (!$cart->merchant_id) {
            return ApiResponse::error('Merchant cart tidak valid', 422);
        }

        $address = null;
        if ($request->filled('address_id')) {
            $address = Address::query()
                ->where('id', $request->integer('address_id'))
                ->where('addressable_id', $user->id)
                ->where('addressable_type', get_class($user))
                ->with(['province', 'city', 'district', 'village'])
                ->first();
        } else {
            $address = $user->primaryAddress()
                ->with(['province', 'city', 'district', 'village'])
                ->first();
        }

        if (!$address) {
            return ApiResponse::error('Alamat tidak ditemukan', 422);
        }

        $order = null;
        $snapToken = null;
        $snapRedirectUrl = null;

        try {
            DB::transaction(function () use ($request, $cart, $user, $address, &$order) {
                $orderCode = $this->generateOrderCode();

                $productSubtotal = 0;
                $addonSubtotal = 0;

                foreach ($cart->items as $cartItem) {
                    $unitPrice = (float) $cartItem->price_snapshot;
                    $quantity = (int) $cartItem->quantity;

                    $productSubtotal += $unitPrice * $quantity;
                    $addonSubtotal += (float) $cartItem->addons->sum('addon_price_snapshot') * $quantity;
                }

                $subtotal = $productSubtotal + $addonSubtotal;
                $discountTotal = 0;
                $deliveryFee = 0;
                $grossAmount = $subtotal - $discountTotal + $deliveryFee;

                $order = Order::query()->create([
                    'address_id' => $address->id,
                    'user_id' => $user->id,
                    'merchant_id' => $cart->merchant_id,
                    'voucher_id' => $request->input('voucher_id'),
                    'order_code' => $orderCode,
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'gross_amount' => $grossAmount,
                    'delivery_fee_snapshot' => $deliveryFee,
                    'status' => 'pending',
                    'user_name_snapshot' => (string) ($user->name ?? ''),
                    'user_phone_snapshot' => (string) ($user->phone ?? ''),
                    'address_detail_snapshot' => (string) ($address->detail ?? ''),
                    'province_name_snapshot' => (string) ($address->province?->name ?? ''),
                    'city_name_snapshot' => (string) ($address->city?->name ?? ''),
                    'district_name_snapshot' => (string) ($address->district?->name ?? ''),
                    'village_name_snapshot' => (string) ($address->village?->name ?? ''),
                    'latitude_snapshot' => $address->latitude,
                    'longitude_snapshot' => $address->longitude,
                ]);

                foreach ($cart->items as $cartItem) {
                    /** @var Product $product */
                    $product = $cartItem->itemable;

                    $unitPrice = (float) $cartItem->price_snapshot;
                    $quantity = (int) $cartItem->quantity;
                    $productSubtotalRow = $unitPrice * $quantity;

                    $orderItem = ProductOrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $cartItem->product_variant_id,
                        'product_name_snapshot' => (string) ($cartItem->itemable_name_snapshot ?? $product->name ?? ''),
                        'product_variant_snapshot' => $cartItem->product_variant_name_snapshot,
                        'sku_snapshot' => $cartItem->variant?->sku,
                        'image_snapshot_path' => (string) ($cartItem->image_snapshot_path ?? ''),
                        'quantity' => $quantity,
                        'unit_price_snapshot' => $unitPrice,
                        'subtotal_snapshot' => $productSubtotalRow,
                    ]);

                    foreach ($cartItem->addons as $cartAddon) {
                        ProductOrderItemAddon::query()->create([
                            'product_order_item_id' => $orderItem->id,
                            'addon_id' => $cartAddon->addon_id,
                            'addon_name_snapshot' => $cartAddon->addon_name_snapshot,
                            'addon_price_snapshot' => $cartAddon->addon_price_snapshot,
                        ]);
                    }
                }
            });

            if (!$order instanceof Order) {
                return ApiResponse::error('Gagal membuat order', 500);
            }

            $this->configureMidtrans();
            $snapPayload = $this->buildMidtransSnapPayload($order);
            $snapToken = Snap::getSnapToken($snapPayload);
            $snapRedirectUrl = Snap::getSnapUrl($snapPayload);
        } catch (\Throwable $e) {
            return ApiResponse::error('Gagal checkout order', 500, [
                'error' => $e->getMessage(),
            ]);
        }

        if (!$order instanceof Order) {
            return ApiResponse::error('Gagal checkout order', 500);
        }

        return ApiResponse::success([
            'order' => $order->load(['items.addons']),
            'midtrans' => [
                'snap_token' => $snapToken,
                'redirect_url' => $snapRedirectUrl,
            ],
        ], 'Order created');
    }

    public function midtransNotification(Request $request)
    {
        $orderId = (string) $request->input('order_id');
        $statusCode = (string) $request->input('status_code');
        $grossAmount = (string) $request->input('gross_amount');
        $signatureKey = (string) $request->input('signature_key');

        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '') {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $serverKey = (string) config('midtrans.server_key');
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!hash_equals($expectedSignature, $signatureKey)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $order = Order::query()->where('order_code', $orderId)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $transactionStatus = (string) $request->input('transaction_status');
        $fraudStatus = (string) $request->input('fraud_status');

        if (in_array($transactionStatus, ['capture', 'settlement'], true)) {
            if ($fraudStatus === 'challenge') {
                // keep pending (manual review)
                $order->status = 'pending';
            } else {
                $order->status = 'paid';
                $order->paid_at = now();
            }
        } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'], true)) {
            $order->status = 'cancelled';
            $order->cancelled_at = now();
        } elseif ($transactionStatus === 'pending') {
            $order->status = 'pending';
        }

        $order->save();

        return response()->json(['message' => 'OK']);
    }

    private function configureMidtrans(): void
    {
        MidtransConfig::$serverKey = config('midtrans.server_key');
        MidtransConfig::$isProduction = (bool) config('midtrans.is_production');
        MidtransConfig::$isSanitized = (bool) config('midtrans.is_sanitized');
        MidtransConfig::$is3ds = (bool) config('midtrans.is_3ds');
    }

    private function buildMidtransSnapPayload(Order $order): array
    {
        $order->loadMissing(['items.addons', 'user']);

        $grossAmount = (int) round((float) $order->gross_amount);
        if ($grossAmount < 1) {
            $grossAmount = 1;
        }

        $itemDetails = [];

        foreach ($order->items as $item) {
            $productName = (string) ($item->product_name_snapshot ?: 'Product');
            $itemDetails[] = [
                'id' => 'product-' . $item->product_id,
                'price' => (int) round((float) $item->unit_price_snapshot),
                'quantity' => (int) $item->quantity,
                'name' => Str::limit($productName, 50, ''),
            ];

            foreach ($item->addons as $addon) {
                $addonName = (string) ($addon->addon_name_snapshot ?: 'Addon');
                $itemDetails[] = [
                    'id' => 'addon-' . $addon->addon_id,
                    'price' => (int) round((float) $addon->addon_price_snapshot),
                    'quantity' => (int) $item->quantity,
                    'name' => Str::limit($addonName, 50, ''),
                ];
            }
        }

        $customerDetails = [
            'first_name' => (string) ($order->user_name_snapshot ?? ''),
            'email' => (string) ($order->user?->email ?? ''),
            'phone' => (string) ($order->user_phone_snapshot ?? ''),
        ];

        return [
            'transaction_details' => [
                'order_id' => (string) $order->order_code,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => $customerDetails,
        ];
    }

    private function generateOrderCode(): string
    {
        do {
            $code = 'ORD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
        } while (Order::query()->where('order_code', $code)->exists());

        return $code;
    }
}
