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
use App\Models\ShippingSetting;
use App\Services\XenditInvoiceService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(private readonly XenditInvoiceService $xenditInvoiceService) {}

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
            'delivery_type' => 'nullable|in:pickup,delivery',
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

        $deliveryType = (string) $request->input('delivery_type', 'pickup');
        $userAddressableTypes = array_values(array_unique([
            $user->getMorphClass(),
            get_class($user),
        ]));

        $address = null;
        if ($request->filled('address_id')) {
            $address = Address::query()
                ->where('id', $request->integer('address_id'))
                ->where('addressable_id', $user->id)
                ->whereIn('addressable_type', $userAddressableTypes)
                ->with(['province', 'city', 'district', 'village'])
                ->first();
        } elseif ($deliveryType === 'delivery') {
            $address = $user->primaryAddress()
                ->with(['province', 'city', 'district', 'village'])
                ->first();
        }

        if ($deliveryType === 'delivery' && !$address) {
            return ApiResponse::error('Alamat tidak ditemukan', 422);
        }

        $order = null;
        $payment = null;

        try {
            DB::transaction(function () use ($request, $cart, $user, $address, $deliveryType, &$order, &$payment) {
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

                // Calculate delivery fee from ShippingSetting
                $deliveryFee = 0;
                if ($deliveryType === 'delivery') {
                    $deliveryFee = $this->calculateDeliveryFee($cart->merchant_id, $address);
                }

                $grossAmount = $subtotal - $discountTotal + $deliveryFee;
                $platformFee = 0;
                $netAmount = max(0, $grossAmount - $platformFee);

                $order = Order::query()->create([
                    'address_id' => $address?->id,
                    'user_id' => $user->id,
                    'merchant_id' => $cart->merchant_id,
                    'voucher_id' => $request->input('voucher_id'),
                    'order_code' => $orderCode,
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'platform_fee' => $platformFee,
                    'gross_amount' => $grossAmount,
                    'net_amount' => $netAmount,
                    'delivery_fee_snapshot' => $deliveryFee,
                    'delivery_type' => $deliveryType,
                    'status' => 'pending',
                    'user_name_snapshot' => (string) ($user->name ?? ''),
                    'user_phone_snapshot' => (string) ($user->phone ?? ''),
                    'address_detail_snapshot' => (string) ($address->detail ?? ''),
                    'province_name_snapshot' => (string) ($address->province?->name ?? ''),
                    'city_name_snapshot' => (string) ($address->city?->name ?? ''),
                    'district_name_snapshot' => (string) ($address->district?->name ?? ''),
                    'village_name_snapshot' => (string) ($address->village?->name ?? ''),
                    'latitude_snapshot' => $address?->latitude,
                    'longitude_snapshot' => $address?->longitude,
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

                $payment = $this->xenditInvoiceService->createOrGetPendingInvoice($order);
            });

            if (!$order instanceof Order || !$payment) {
                return ApiResponse::error('Gagal membuat order', 500);
            }
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
            'xendit' => [
                'payment_id' => $payment?->id,
                'external_id' => $payment?->external_id,
                'invoice_url' => $payment?->invoice_url,
            ],
        ], 'Order created');
    }

    /**
     * Calculate delivery fee based on ShippingSetting and distance.
     */
    private function calculateDeliveryFee(int $merchantId, Address $customerAddress): float
    {
        $merchant = Merchant::query()->find($merchantId);
        if (!$merchant) {
            return 0;
        }

        $merchantAddress = $merchant->primaryAddress()->first();
        if (!$merchantAddress || !$merchantAddress->latitude || !$merchantAddress->longitude) {
            return 0;
        }

        if (!$customerAddress->latitude || !$customerAddress->longitude) {
            return 0;
        }

        $setting = ShippingSetting::query()->where('status', 'active')->first();
        if (!$setting) {
            return 0;
        }

        $baseCost = (float) $setting->base_cost;
        $costPerKm = (float) $setting->cost_per_km;

        $distanceKm = ShippingController::haversineDistance(
            (float) $merchantAddress->latitude,
            (float) $merchantAddress->longitude,
            (float) $customerAddress->latitude,
            (float) $customerAddress->longitude,
        );

        $fee = $baseCost + ($costPerKm * $distanceKm);

        // Round up to nearest 500
        return (float) (ceil($fee / 500) * 500);
    }

    private function generateOrderCode(): string
    {
        do {
            $code = 'ORD-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
        } while (Order::query()->where('order_code', $code)->exists());

        return $code;
    }
}
