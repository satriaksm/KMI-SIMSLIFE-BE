<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Events\OrderStatusUpdated;
use App\Helpers\ApiResponse;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\MerchantWalletHistory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductOrderItem;
use App\Models\ProductOrderItemAddon;
use App\Models\ShippingSetting;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\XenditInvoiceService;
use App\Services\WebPushService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly WebPushService $webPushService
    ) {}

    public function customerIndex(Request $request)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $activeOrders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'paid'])
            ->where(function ($q) {
                $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
            })
            ->with(['payment'])
            ->get();

        foreach ($activeOrders as $order) {
            $this->checkAndAutoCancelOrder($order);
        }

        $query = Order::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
            })
            ->with([
                'merchant',
                'orderItems.product',
                'items.addons.addon',
                'payment',
            ])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        $orders = $query->paginate($perPage)->appends($request->query());

        return ApiResponse::success(
            $orders->items(),
            'Orders fetched',
            200,
            [
                'pagination' => [
                    'total'        => $orders->total(),
                    'per_page'     => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page'    => $orders->lastPage(),
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

        if ($this->checkAndAutoCancelOrder($order)) {
            $order->refresh();
        }

        return ApiResponse::success(
            $order->load([
                'merchant.primaryAddress.village',
                'merchant.primaryAddress.district',
                'merchant.primaryAddress.city',
                'merchant.primaryAddress.province',
                'orderItems.product',
                'items.product',
                'items.variant',
                'items.addons.addon',
                'items.review.media',
                'payment',
            ]),
            'Order fetched'
        );
    }

    public function complete(Order $order)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $order->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        if ($order->delivery_type === 'pickup') {
            if ($order->status !== 'ready_to_pickup') {
                return ApiResponse::error('Pesanan hanya dapat diselesaikan jika statusnya sudah siap diambil (ready_to_pickup).', 422);
            }
        } else {
            if ($order->status !== 'delivered') {
                return ApiResponse::error('Pesanan hanya dapat diselesaikan jika statusnya sudah diantar (delivered).', 422);
            }
        }

        if (strtoupper($order->payment_method ?? '') === 'COD') {
            return ApiResponse::error('Pesanan COD hanya dapat diselesaikan oleh penjual/kurir saat menerima pembayaran.', 422);
        }

        $order->status = 'completed';
        $order->completed_at = now();
        $order->save();

        // Pindahkan saldo dari balance_pending → balance_available
        $this->moveBalanceToAvailable($order);

        $updatedOrder = $order->fresh();
        event(new OrderStatusUpdated($updatedOrder));
        try {
            $this->webPushService->sendOrderStatusUpdate($updatedOrder);
        } catch (\Throwable $e) {
            Log::warning('[WebPush] complete sendOrderStatusUpdate failed', ['order_id' => $updatedOrder->id, 'error' => $e->getMessage()]);
        }

        return ApiResponse::success(
            $order->load(['items.addons.addon']),
            'Order completed'
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

        if (in_array($order->status, ['paid', 'responsed', 'accepted', 'ready_to_pickup', 'delivered', 'completed', 'cancelled'], true)) {
            return ApiResponse::error('Order tidak bisa dibatalkan', 422);
        }


        $order->status = 'cancelled';
        $order->cancelled_at = now();
        $order->save();

        $updatedOrder = $order->fresh();
        event(new OrderStatusUpdated($updatedOrder));
        try {
            $this->webPushService->sendOrderStatusUpdate($updatedOrder, 'customer_cancel');
        } catch (\Throwable $e) {
            Log::warning('[WebPush] cancel sendOrderStatusUpdate failed', ['order_id' => $updatedOrder->id, 'error' => $e->getMessage()]);
        }

        return ApiResponse::success(
            $order->load(['items.addons.addon']),
            'Order cancelled'
        );
    }

    /**
     * Merchant: list pesanan — hanya tampilkan yang sudah bayar atau status relevan (bukan pending).
     */
    public function merchantIndex(Request $request, Merchant $merchant)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ApiResponse::error('Forbidden', 403);
        }

        if ($merchant->segmentation_id === 3) {
            return app(\App\Http\Controllers\JasaOrderController::class)->merchantOrders($request, $merchant->slug);
        }

        $activeOrders = Order::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('status', ['pending', 'paid'])
            ->where(function ($q) {
                $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
            })
            ->with(['payment'])
            ->get();

        foreach ($activeOrders as $order) {
            $this->checkAndAutoCancelOrder($order);
        }

        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($q) {
                $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
            })
            ->with([
                'orderItems.product',
                'items.addons.addon',
                'payment'
            ]);

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($qBuilder) use ($search) {
                $qBuilder->where('order_code', 'like', "%{$search}%")
                    ->orWhere('user_name_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($itemQ) use ($search) {
                        $itemQ->where('product_name_snapshot', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = \Carbon\Carbon::parse($request->input('start_date'))->startOfDay();
            $endDate = \Carbon\Carbon::parse($request->input('end_date'))->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $waitingReviewStatuses = ['paid', 'sudah_bayar'];
        $pendingReviewStatuses = ['pending', 'menunggu_konfirmasi', 'menunggu_konfirmasi_merchant', 'processing_payment'];

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $reqStatus = $request->input('status');
            if ($reqStatus === 'waiting_review') {
                $query->where(function ($q) use ($waitingReviewStatuses, $pendingReviewStatuses) {
                    $q->whereIn('status', $waitingReviewStatuses)
                        ->orWhere('payment_status', 'PAID')
                        ->orWhere(function ($q2) use ($pendingReviewStatuses) {
                            $q2->whereIn('status', $pendingReviewStatuses)->where('payment_method', 'COD');
                        });
                });
            } elseif ($reqStatus === 'processing') {
                $query->whereIn('status', ['responsed', 'accepted']);
            } elseif ($reqStatus === 'delivered') {
                $query->whereIn('status', ['delivered', 'ready_to_pickup']);
            } elseif ($reqStatus === 'cancelled') {
                $query->whereIn('status', ['cancelled', 'rejected', 'undelivered', 'unpicked']);
            } else {
                $query->where('status', $reqStatus);
            }
        } else {
            $query->where(function ($qBuilder) use ($pendingReviewStatuses) {
                $qBuilder->whereNotIn('status', $pendingReviewStatuses)
                    ->orWhere('payment_method', 'COD');
            });
        }

        $sortBy = $request->input('sort_by', 'newest');
        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        $orders = $query->paginate($perPage)->appends($request->query());

        $counts = [
            'waiting_review' => Order::where('merchant_id', $merchant->id)
                ->where(function ($q) {
                    $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
                })
                ->where(function ($q) use ($waitingReviewStatuses, $pendingReviewStatuses) {
                    $q->whereIn('status', $waitingReviewStatuses)
                        ->orWhere('payment_status', 'PAID')
                        ->orWhere(function ($q2) use ($pendingReviewStatuses) {
                            $q2->whereIn('status', $pendingReviewStatuses)->where('payment_method', 'COD');
                        });
                })->count(),
            'processing' => Order::where('merchant_id', $merchant->id)
                ->where(function ($q) {
                    $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
                })
                ->whereIn('status', ['responsed', 'accepted'])->count(),
            'delivered' => Order::where('merchant_id', $merchant->id)
                ->where(function ($q) {
                    $q->where('order_type', '!=', 'jasa')->orWhereNull('order_type');
                })
                ->whereIn('status', ['delivered', 'ready_to_pickup'])->count(),
        ];

        return ApiResponse::success(
            $orders->items(),
            'Merchant orders fetched',
            200,
            [
                'pagination' => [
                    'total'        => $orders->total(),
                    'per_page'     => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page'    => $orders->lastPage(),
                    'next_page_url' => $orders->nextPageUrl(),
                    'prev_page_url' => $orders->previousPageUrl(),
                ],
                'counts' => $counts
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

        \Illuminate\Support\Facades\Log::info('Merchant order detail', [
            'merchant' => $merchant->id ?? null,
            'order_id' => $order->id ?? null,
            'order_type' => $order->order_type ?? null,
        ]);

        $isJasaOrder = ($order->order_type === 'jasa' || $order->jasaItems()->exists() || $merchant->segmentation_id === 3);

        if ($isJasaOrder) {
            \Illuminate\Support\Facades\Log::info('Delegating order detail to JasaOrderController', [
                'order_id' => $order->id,
            ]);
            return app(\App\Http\Controllers\JasaOrderController::class)->merchantShow(request(), $merchant->slug, $order->id);
        }

        \Illuminate\Support\Facades\Log::info('Handling order detail as product order', [
            'order_id' => $order->id,
        ]);

        if ((int) $order->merchant_id !== (int) $merchant->id) {
            \Illuminate\Support\Facades\Log::warning('Order merchant mismatch', [
                'order_merchant_id' => $order->merchant_id,
                'request_merchant_id' => $merchant->id,
            ]);
            return ApiResponse::error('Order tidak ditemukan', 404);
        }

        if ($this->checkAndAutoCancelOrder($order)) {
            $order->refresh();
        }

        return ApiResponse::success(
            $order->load([
                'merchant.primaryAddress',
                'orderItems.product',
                'items.product',
                'items.variant',
                'items.addons.addon',
                'items.review',
                'payment',
                'user'
            ]),
            'Order fetched'
        );
    }

    /**
     * Merchant: update status pesanan.
     *
     * Flow baru:
     *   paid (Transfer) → accepted (terima) | cancelled (tolak)
     *   pending COD     → accepted (terima) | cancelled (tolak)
     *   accepted        → delivered / ready_to_pickup
     *   delivered       → completed / undelivered
     *   ready_to_pickup → completed / unpicked
     */
    public function updateStatus(Request $request, Merchant $merchant, Order $order)
    {
        if ($merchant->segmentation_id === 3) {
            return app(\App\Http\Controllers\JasaOrderController::class)->updateStatus($request, $merchant->slug, $order->id);
        }

        $request->validate([
            'status' => 'required|in:responsed,accepted,rejected,delivered,completed,cancelled,undelivered,ready_to_pickup,unpicked',
            'proof_image' => 'nullable|image|max:5120',
            'failed_reason' => 'nullable|string|max:1000',
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
            // UMKM bisa terima pesanan yang sudah bayar (paid) atau COD (pending delivery_type=pickup/delivery)
            'responsed', 'accepted' => in_array($order->status, ['paid', 'pending'], true),
            'rejected' => in_array($order->status, ['paid', 'pending', 'responsed', 'accepted'], true),
            'delivered' => in_array($order->status, ['responsed', 'accepted'], true) && $order->delivery_type === 'delivery',
            'ready_to_pickup' => in_array($order->status, ['responsed', 'accepted'], true) && $order->delivery_type === 'pickup',
            'completed' => (in_array($order->status, ['delivered'], true) && $order->delivery_type === 'delivery') ||
                (in_array($order->status, ['ready_to_pickup'], true) && $order->delivery_type === 'pickup'),
            'undelivered' => in_array($order->status, ['delivered'], true) && $order->delivery_type === 'delivery',
            'unpicked' => in_array($order->status, ['ready_to_pickup'], true) && $order->delivery_type === 'pickup',
            'cancelled' => in_array($order->status, ['paid', 'pending', 'responsed', 'accepted'], true),
            default     => false,
        };

        // COD (pending + pickup/delivery) boleh diterima UMKM
        // Transfer (pending) TIDAK boleh diterima UMKM — harus bayar dulu
        if (in_array($newStatus, ['responsed', 'accepted', 'rejected']) && $order->status === 'pending') {
            // Hanya izinkan jika COD (belum ada payment)
            $hasPendingPayment = $order->payment()->where('status', 'pending')->exists();
            if ($hasPendingPayment) {
                return ApiResponse::error('Pesanan belum dibayar. Tunggu pembayaran dari pembeli.', 422);
            }
        }

        if ($newStatus === 'completed') {
            $isPickup = $order->delivery_type === 'pickup';
            // UMKM can complete any order now, but if it's delivery they must upload proof
            if (!$isPickup && !$request->hasFile('proof_image')) {
                return ApiResponse::error('Bukti foto pengiriman wajib diunggah saat pesanan tiba.', 422);
            }
        }

        if ($newStatus === 'undelivered' && !$request->hasFile('proof_image')) {
            return ApiResponse::error('Bukti foto wajib diunggah untuk pesanan gagal kirim.', 422);
        }

        if (!$allowed) {
            return ApiResponse::error('Perubahan status tidak valid', 422);
        }

        $order->status = $newStatus;
        if ($newStatus === 'responsed' || $newStatus === 'accepted') {
            $order->status = 'accepted';
            $order->accepted_at = now();
            // Backward compatibility
            $order->responsed_at = now();
        } elseif ($newStatus === 'rejected') {
            $order->rejected_at = now();
            $this->refundBalancePendingIfNeeded($order);
        } elseif ($newStatus === 'delivered') {
            $order->delivered_at = now();
            // Photo is now uploaded when completed/arrived, not here
        } elseif ($newStatus === 'ready_to_pickup') {
            $order->ready_to_pickup_at = now();
        } elseif ($newStatus === 'undelivered') {
            if ($request->hasFile('proof_image')) {
                $path = $request->file('proof_image')->store('orders/proofs', 'public');
                $order->proof_image_path = $path;
            }
            if ($request->filled('failed_reason')) {
                $order->failed_reason = $request->input('failed_reason');
            }

            // Release funds for undelivered as the UMKM has prepared and tried to deliver
            $this->moveBalanceToAvailable($order);
        } elseif ($newStatus === 'unpicked') {
            $order->unpicked_at = now();
            $this->moveBalanceToAvailable($order);
        } elseif ($newStatus === 'completed') {
            $order->completed_at = now();
            if ($request->hasFile('proof_image')) {
                $path = $request->file('proof_image')->store('orders/proofs', 'public');
                $order->proof_image_path = $path;
            }
            $this->moveBalanceToAvailable($order);
        } elseif ($newStatus === 'cancelled') {
            $order->cancelled_at = now();
            $this->refundBalancePendingIfNeeded($order);
        }

        // Simpan alasan pembatalan/penolakan jika ada
        if (in_array($newStatus, ['rejected', 'cancelled', 'undelivered', 'unpicked'])) {
            if ($request->filled('failed_reason')) {
                $order->failed_reason = $request->input('failed_reason');
            }
        }

        $order->save();

        $updatedOrder = $order->fresh();
        event(new OrderStatusUpdated($updatedOrder));

        $cancelContext = null;
        if ($newStatus === 'cancelled') $cancelContext = 'merchant_reject';
        if ($newStatus === 'rejected') $cancelContext = 'merchant_reject';

        try {
            $this->webPushService->sendOrderStatusUpdate($updatedOrder, $cancelContext);
        } catch (\Throwable $e) {
            Log::warning('[WebPush] updateStatus sendOrderStatusUpdate failed', ['order_id' => $updatedOrder->id, 'error' => $e->getMessage()]);
        }

        return ApiResponse::success(
            $order->load(['items.addons.addon']),
            'Order status updated'
        );
    }

    public function checkoutProductFromCart(Request $request)
    {
        $request->validate([
            'cart_id'       => 'required|integer|exists:carts,id',
            'address_id'    => 'nullable|integer|exists:addresses,id',
            'voucher_id'    => 'nullable|integer|exists:vouchers,id',
            'delivery_type' => 'nullable|in:pickup,delivery',
            'payment_method' => 'nullable|string',
            'notes'         => 'nullable|string|max:500',
        ], [
            'cart_id.required' => 'Keranjang wajib dipilih.',
            'cart_id.integer'  => 'ID keranjang tidak valid.',
            'cart_id.exists'   => 'Keranjang tidak ditemukan. Silakan tambahkan produk ke keranjang lagi.',
            'address_id.exists' => 'Alamat pengiriman tidak valid.',
            'voucher_id.exists' => 'Voucher tidak valid.',
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

        if (!$cart->merchant_id || !$cart->merchant) {
            return ApiResponse::error('Merchant cart tidak valid', 422);
        }

        if ($cart->merchant->user_id === $user->id) {
            return ApiResponse::error('Anda tidak dapat membeli produk dari toko Anda sendiri.', 403);
        }

        if (!$cart->merchant->is_open_now) {
            return ApiResponse::error('UMKM sedang tutup. Anda tidak dapat membuat pesanan saat ini.', 400);
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

        // ========================
        // VALIDASI VOUCHER
        // ========================
        $voucher = null;
        if ($request->filled('voucher_id')) {
            $voucher = Voucher::query()
                ->where('id', $request->integer('voucher_id'))
                ->active()
                ->first();

            if (!$voucher) {
                return ApiResponse::error('Voucher tidak valid atau sudah kadaluarsa', 422);
            }

            // Cek limit per user
            if (!VoucherUsage::canUseVoucher($user->id, $voucher->id, $voucher)) {
                return ApiResponse::error('Batas pemakaian voucher sudah tercapai', 422);
            }

            // Cek total limit
            if ($voucher->usage_limit !== null) {
                $totalUsed = VoucherUsage::where('voucher_id', $voucher->id)->count();
                if ($totalUsed >= $voucher->usage_limit) {
                    return ApiResponse::error('Voucher sudah habis digunakan', 422);
                }
            }
        }

        $order = null;
        $payment = null;
        $inventoryUpdates = [];

        try {
            DB::transaction(function () use ($request, $cart, $user, $address, $deliveryType, $voucher, &$order, &$payment, &$inventoryUpdates) {
                $orderCode = $this->generateOrderCode();

                $productSubtotal = 0;
                $addonSubtotal   = 0;

                foreach ($cart->items as $cartItem) {
                    $unitPrice = (float) $cartItem->price_snapshot;
                    $quantity  = (int) $cartItem->quantity;

                    $productSubtotal += $unitPrice * $quantity;
                    $addonSubtotal   += (float) $cartItem->addons->sum('addon_price_snapshot') * $quantity;
                }

                $subtotal = $productSubtotal + $addonSubtotal;

                // ========================
                // HITUNG DISKON VOUCHER
                // ========================
                $discountTotal = 0;
                if ($voucher) {
                    if ($subtotal >= (float) $voucher->min_purchase_amount) {
                        if ($voucher->voucher_type === 'fixed') {
                            $discountTotal = min((float) $voucher->value, $subtotal);
                        } elseif ($voucher->voucher_type === 'percent') {
                            $discountTotal = floor(((float) $voucher->value / 100) * $subtotal);
                            if ($voucher->max_discount_amount) {
                                $discountTotal = min($discountTotal, (float) $voucher->max_discount_amount);
                            }
                        }
                    }
                }

                // ========================
                // ONGKIR
                // ========================
                $deliveryFee = 0;
                if ($deliveryType === 'delivery') {
                    $deliveryFee = $this->calculateDeliveryFee($cart->merchant_id, $address);
                }

                $baseGross = max(0, $subtotal - $discountTotal + $deliveryFee);
                $paymentMethod = strtoupper($request->input('payment_method', 'Transfer'));

                $platformFee = 0;
                if ($paymentMethod !== 'COD') {
                    $vaMethods = ['BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'CIMB'];
                    $ewallet15 = ['OVO', 'DANA', 'LINKAJA'];
                    $feeCode = 'VA'; // Default
                    if ($paymentMethod === 'QRIS') {
                        $feeCode = 'QRIS';
                    } elseif (in_array($paymentMethod, $ewallet15)) {
                        $feeCode = 'EWALLET';
                    } elseif ($paymentMethod === 'SHOPEEPAY') {
                        $feeCode = 'SHOPEEPAY';
                    } elseif ($paymentMethod === 'ALFAMART' || $paymentMethod === 'INDOMARET') {
                        $feeCode = 'RETAIL';
                    }

                    $feeConfig = \App\Models\PaymentFee::where('method_code', $feeCode)->first();

                    if ($feeConfig) {
                        if ($feeConfig->type === 'percentage') {
                            $platformFee = (int) ceil($baseGross * ($feeConfig->value / 100));
                        } else {
                            $platformFee = (int) $feeConfig->value;
                        }
                    } else {
                        // Fallback aman
                        $platformFee = 4440;
                    }
                }

                $grossAmount = $baseGross + $platformFee;
                $netAmount   = max(0, $grossAmount - $platformFee);

                // Batas waktu UMKM konfirmasi pesanan (menit)
                $confirmMinutes = (int) config('app.order_confirm_minutes', 10);

                $order = Order::query()->create([
                    'order_type'              => 'product',
                    'address_id'              => $address?->id,
                    'user_id'                 => $user->id,
                    'merchant_id'             => $cart->merchant_id,
                    'voucher_id'              => $voucher?->id,
                    'order_code'              => $orderCode,
                    'subtotal'                => $subtotal,
                    'discount_total'          => $discountTotal,
                    'platform_fee'            => $platformFee,
                    'gross_amount'            => $grossAmount,
                    'net_amount'              => $netAmount,
                    'delivery_fee_snapshot'   => $deliveryFee,
                    'delivery_type'           => $deliveryType,
                    'status'                  => 'pending',
                    'user_name_snapshot'      => (string) ($user->name ?? ''),
                    'user_phone_snapshot'     => (string) ($user->phone ?? ''),
                    'address_detail_snapshot' => (string) ($address->detail ?? ''),
                    'province_name_snapshot'  => (string) ($address->province?->name ?? ''),
                    'city_name_snapshot'      => (string) ($address->city?->name ?? ''),
                    'district_name_snapshot'  => (string) ($address->district?->name ?? ''),
                    'village_name_snapshot'   => (string) ($address->village?->name ?? ''),
                    'latitude_snapshot'       => $address?->latitude,
                    'longitude_snapshot'      => $address?->longitude,
                    'notes'                   => $request->input('notes'),
                    'payment_method'          => $paymentMethod,
                    // COD: langsung set deadline konfirmasi; Transfer: set setelah bayar
                    'confirm_deadline'        => $paymentMethod === 'COD' ? now()->addMinutes($confirmMinutes) : null,
                ]);

                foreach ($cart->items as $cartItem) {
                    /** @var Product $product */
                    $product  = $cartItem->itemable;
                    $unitPrice = (float) $cartItem->price_snapshot;
                    $quantity  = (int) $cartItem->quantity;

                    $orderItem = ProductOrderItem::query()->create([
                        'order_id'                   => $order->id,
                        'product_id'                 => $product->id,
                        'product_variant_id'         => $cartItem->product_variant_id,
                        'product_name_snapshot'      => (string) ($cartItem->itemable_name_snapshot ?? $product->name ?? ''),
                        'product_variant_snapshot'   => $cartItem->product_variant_name_snapshot,
                        'sku_snapshot'               => $cartItem->variant?->sku,
                        'image_snapshot_path'        => (string) ($cartItem->image_snapshot_path ?? ''),
                        'quantity'                   => $quantity,
                        'unit_price_snapshot'        => $unitPrice,
                        'subtotal_snapshot'          => $unitPrice * $quantity,
                    ]);

                    if ($cartItem->product_variant_id) {
                        ProductVariant::query()
                            ->where('id', $cartItem->product_variant_id)
                            ->update([
                                'stock' => DB::raw('GREATEST(stock - ' . $quantity . ', 0)'),
                            ]);

                        $inventoryUpdates[] = [
                            'product_id' => $product->id,
                            'variant_id' => $cartItem->product_variant_id,
                        ];
                    }

                    foreach ($cartItem->addons as $cartAddon) {
                        ProductOrderItemAddon::query()->create([
                            'product_order_item_id' => $orderItem->id,
                            'addon_id'              => $cartAddon->addon_id,
                            'addon_name_snapshot'   => $cartAddon->addon_name_snapshot,
                            'addon_price_snapshot'  => $cartAddon->addon_price_snapshot,
                        ]);
                    }
                }

                // ========================
                // CATAT PEMAKAIAN VOUCHER
                // ========================
                if ($voucher && $discountTotal > 0) {
                    VoucherUsage::create([
                        'user_id'         => $user->id,
                        'voucher_id'      => $voucher->id,
                        'order_id'        => $order->id,
                        'discount_amount' => $discountTotal,
                    ]);
                }

                // ========================
                // BUAT INVOICE XENDIT (hanya untuk transfer/QRIS)
                // Untuk COD (pickup), tidak perlu Xendit
                // ========================
                $paymentMethodValue = strtoupper($request->input('payment_method', 'Transfer'));
                if ($paymentMethodValue !== 'COD' && $grossAmount > 0) {
                    $payment = $this->xenditInvoiceService->createOrGetPendingInvoice($order);
                }

                // ========================
                // HAPUS CART SETELAH CHECKOUT
                // ========================
                // Do not delete snapshot image here because ProductOrderItem relies on it.
                $cart->items()->delete();
                $cart->delete();
            });

            if (!$order instanceof Order) {
                return ApiResponse::error('Gagal membuat order', 500);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Checkout 500 error:', [
                'request' => $request->all(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponse::error('Gagal checkout order', 500, [
                'error' => $e->getMessage(),
            ]);
        }

        $freshOrder = $order->fresh();
        event(new OrderCreated($freshOrder));

        foreach ($inventoryUpdates as $update) {
            $stock = (int) ProductVariant::query()
                ->where('id', $update['variant_id'])
                ->value('stock');

            event(new \App\Events\InventoryStockUpdated(
                (int) $update['product_id'],
                (int) $update['variant_id'],
                $stock
            ));
        }

        try {
            $this->webPushService->notifyOrderCreated($freshOrder);
        } catch (\Throwable $e) {
            // WebPush is non-critical — log and continue so checkout is not blocked
            \Illuminate\Support\Facades\Log::warning('[WebPush] notifyOrderCreated failed', [
                'order_id' => $freshOrder->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return ApiResponse::success([
            'order'  => $order->load(['items.addons']),
            'xendit' => [
                'payment_id'  => $payment?->id,
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
        if (!$merchant) return 0;

        $merchantAddress = $merchant->primaryAddress()->first();
        if (!$merchantAddress || !$merchantAddress->latitude || !$merchantAddress->longitude) return 0;
        if (!$customerAddress->latitude || !$customerAddress->longitude) return 0;

        $setting = ShippingSetting::query()->where('status', 'active')->first();

        $baseCost  = $setting ? (float) $setting->base_cost : 15000;
        $costPerKm = $setting ? (float) $setting->cost_per_km : 5000;

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
    private function checkAndAutoCancelOrder(Order $order): bool
    {
        $changed = false;

        // 1. Pending payment expired (Transfer belum bayar)
        if ($order->status === 'pending' && $order->payment && $order->payment->expired_at && now()->greaterThan($order->payment->expired_at)) {
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $changed = true;
        }

        // 2. UMKM tidak konfirmasi dalam batas waktu (confirm_deadline)
        //    Berlaku untuk:
        //    - COD (pending) → confirm_deadline diset saat order dibuat
        //    - Transfer (paid) → confirm_deadline diset setelah pembayaran berhasil
        if (in_array($order->status, ['pending', 'paid'], true) && $order->confirm_deadline && now()->greaterThan($order->confirm_deadline)) {
            // Refund jika sudah dibayar
            if ($order->status === 'paid') {
                $this->refundBalancePendingIfNeeded($order);
            }

            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $changed = true;
        }

        // 3. Fallback: jika confirm_deadline belum diset (data lama)
        //    COD tanpa payment, belum ada confirm_deadline → fallback 24 jam dari created_at
        if ($order->status === 'pending' && !$order->confirm_deadline && (!$order->payment || $order->payment->status !== 'pending') && now()->diffInHours($order->created_at) >= 24) {
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $changed = true;
        }

        //    Transfer paid tanpa confirm_deadline → fallback 24 jam dari paid_at
        if ($order->status === 'paid' && !$order->confirm_deadline && $order->paid_at && now()->diffInHours($order->paid_at) >= 24) {
            $this->refundBalancePendingIfNeeded($order);
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $changed = true;
        }

        if ($changed) {
            $order->save();

            $updatedOrder = $order->fresh();
            event(new OrderStatusUpdated($updatedOrder));
            try {
                $this->webPushService->sendOrderStatusUpdate($updatedOrder, 'auto');
            } catch (\Throwable $e) {
                Log::warning('[WebPush] auto-cancel sendOrderStatusUpdate failed', ['order_id' => $updatedOrder->id, 'error' => $e->getMessage()]);
            }
        }

        return $changed;
    }

    /**
     * Pindahkan saldo dari balance_pending → balance_available saat order completed.
     */
    private function moveBalanceToAvailable(Order $order): void
    {
        if (strtoupper($order->payment_method ?? '') === 'COD') return;

        $merchant = $order->merchant;
        if (!$merchant) return;

        $netAmount = (float) ($order->net_amount ?? 0);
        if ($netAmount <= 0) {
            $netAmount = max(0, (float) $order->gross_amount - (float) $order->platform_fee);
        }
        if ($netAmount <= 0) return;

        $merchant->decrement('balance_pending', $netAmount);
        $merchant->increment('balance_available', $netAmount);

        MerchantWalletHistory::create([
            'merchant_id'    => $merchant->id,
            'type'           => 'release',
            'amount'         => $netAmount,
            'reference_type' => 'order',
            'reference_id'   => $order->id,
            'description'    => 'Order completed — released to available balance',
        ]);
    }

    /**
     * Kembalikan saldo dari balance_pending jika order dibatalkan setelah pembayaran.
     */
    private function refundBalancePendingIfNeeded(Order $order): void
    {
        // Hanya refund jika order sudah pernah dibayar (status 'paid')
        if (strtoupper($order->payment_method ?? '') === 'COD') return;
        if (!$order->paid_at) return;

        $merchant = $order->merchant;
        if (!$merchant) return;

        $netAmount = (float) ($order->net_amount ?? 0);
        if ($netAmount <= 0) {
            $netAmount = max(0, (float) $order->gross_amount - (float) $order->platform_fee);
        }
        if ($netAmount <= 0) return;

        // Pastikan balance_pending tidak menjadi negatif
        $currentPending = (float) $merchant->balance_pending;
        $refundAmount = min($netAmount, $currentPending);
        if ($refundAmount <= 0) return;

        $merchant->decrement('balance_pending', $refundAmount);

        MerchantWalletHistory::create([
            'merchant_id'    => $merchant->id,
            'type'           => 'refund',
            'amount'         => $refundAmount,
            'reference_type' => 'order',
            'reference_id'   => $order->id,
            'description'    => 'Order cancelled — refund from pending balance',
        ]);

        // Attempt automatic refund if payment was via Xendit
        if ($order->payment && $order->payment->xendit_invoice_id) {
            $xenditRefundService = app(\App\Services\XenditRefundService::class);
            $success = $xenditRefundService->processRefund($order->payment);

            if (!$success && $order->payment->refund_status === 'failed') {
                // Notifikasi ke admin bahwa refund gagal (butuh manual)
                $admins = \App\Models\User::whereHas('roles', function ($query) {
                    $query->where('name', 'admin');
                })->get();

                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\ManualRefundRequiredNotification($order));
                }
            }
        }
    }
}
