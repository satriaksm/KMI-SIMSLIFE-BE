<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * List merchant orders with filters & pagination
     */
    public function index(Request $request, Merchant $merchant)
    {
        $this->checkMerchantAccess($request, $merchant);

        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with([
                'items.product.coverImage',
                'items.variant',
                'user.primaryAddress.village',
                'user.primaryAddress.district',
                'user.primaryAddress.city',
                'user.primaryAddress.province',
                'user.addresses.village',
                'user.addresses.district',
                'user.addresses.city',
                'user.addresses.province',
                'merchant',
                'jasa',
            ]);

        // Filter: Tab Status
        $status = $request->query('status');
        if ($status && $status !== 'all') {
            if ($status === 'waiting_review') {
                $query->whereIn('status', ['pending', 'paid', 'waiting_review']);
            } elseif ($status === 'completed') {
                $query->whereIn('status', ['completed', 'selesai']);
            } elseif ($status === 'cancelled') {
                $query->whereIn('status', ['cancelled', 'rejected', 'undelivered', 'unpicked', 'batal']);
            } elseif ($status === 'processing') {
                $query->whereIn('status', ['processing', 'responsed', 'delivered', 'proses']);
            } else {
                $query->where('status', $status);
            }
        }

        // Filter: Search query (q)
        if ($request->filled('q')) {
            $q = trim((string) $request->query('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('order_code', 'like', "%{$q}%")
                    ->orWhere('nama', 'like', "%{$q}%")
                    ->orWhere('tel', 'like', "%{$q}%")
                    ->orWhereHas('items.product', function ($pq) use ($q) {
                        $pq->where('name', 'like', "%{$q}%");
                    });
            });
        }

        // Filter: Date range
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->query('end_date'));
        }

        // Sort
        if ($request->query('sort_by') === 'oldest') {
            $query->oldest();
        } else {
            $query->latest();
        }

        // Pagination
        $perPage = (int) $request->query('per_page', 10);
        $orders = $query->paginate($perPage);

        // Calculate tab counts for this merchant
        $allMerchantOrders = Order::where('merchant_id', $merchant->id);
        $counts = [
            'all' => (clone $allMerchantOrders)->count(),
            'waiting_review' => (clone $allMerchantOrders)->whereIn('status', ['pending', 'paid', 'waiting_review'])->count(),
            'processing' => (clone $allMerchantOrders)->whereIn('status', ['processing', 'responsed', 'delivered', 'proses'])->count(),
            'completed' => (clone $allMerchantOrders)->whereIn('status', ['completed', 'selesai'])->count(),
            'cancelled' => (clone $allMerchantOrders)->whereIn('status', ['cancelled', 'rejected', 'undelivered', 'unpicked', 'batal'])->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
                'counts' => $counts,
            ],
        ]);
    }

    /**
     * Show single order detail
     */
    public function show(Request $request, Merchant $merchant, $id)
    {
        $this->checkMerchantAccess($request, $merchant);

        $order = Order::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('order_code', $id);
            })
            ->with([
                'items.product.coverImage',
                'items.variant',
                'user.primaryAddress.village',
                'user.primaryAddress.district',
                'user.primaryAddress.city',
                'user.primaryAddress.province',
                'user.addresses.village',
                'user.addresses.district',
                'user.addresses.city',
                'user.addresses.province',
                'merchant',
                'jasa',
                'voucherUsage.voucher',
            ])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update order status (Confirm completed, cancel, etc.)
     */
    public function updateStatus(Request $request, Merchant $merchant, $id)
    {
        $this->checkMerchantAccess($request, $merchant);

        $data = $request->validate([
            'status' => 'required|string|in:waiting_review,processing,responsed,delivered,completed,cancelled,rejected,selesai,batal',
            'reason' => 'nullable|string|max:500',
            'delivery_type' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|max:50',
            'shipping_fee' => 'nullable|numeric|min:0',
        ]);

        $order = Order::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('order_code', $id);
            })
            ->with(['items.variant', 'voucherUsage'])
            ->firstOrFail();

        $oldStatus = $order->status;
        $targetStatus = $data['status'];

        // Normalize Indonesian status aliases
        if ($targetStatus === 'selesai') {
            $targetStatus = 'completed';
        } elseif ($targetStatus === 'batal') {
            $targetStatus = 'cancelled';
        }

        // If transitioning to cancelled / rejected from an active state, restore inventory stock
        if (
            in_array($targetStatus, ['cancelled', 'rejected'], true) &&
            !in_array($oldStatus, ['cancelled', 'rejected', 'batal'], true)
        ) {
            foreach ($order->items as $item) {
                if ($item->variant) {
                    $item->variant->increment('stock', $item->quantity);
                }
            }
        }

        $updatePayload = [
            'status' => $targetStatus,
        ];

        if (!empty($data['delivery_type'])) {
            $updatePayload['delivery_type'] = $data['delivery_type'];
        }

        if (!empty($data['payment_method'])) {
            $updatePayload['payment_method'] = $data['payment_method'];
            $updatePayload['metode_pembayaran'] = $data['payment_method'];
        }

        if (array_key_exists('shipping_fee', $data) && $data['shipping_fee'] !== null) {
            $newShippingFee = (float) $data['shipping_fee'];
            $subtotal = (float) ($order->subtotal ?? $order->total ?? 0);
            $discount = (float) ($order->discount_total ?? 0);
            $newTotal = max(0, $subtotal + $newShippingFee - $discount);

            $updatePayload['shipping_fee'] = $newShippingFee;
            $updatePayload['total'] = (int) round($newTotal);
        }

        $order->update($updatePayload);

        return response()->json([
            'success' => true,
            'message' => 'Status pesanan berhasil diperbarui',
            'data' => $order->fresh()->load(['items.product.coverImage', 'items.variant', 'user', 'jasa', 'voucherUsage.voucher']),
        ]);
    }

    private function checkMerchantAccess(Request $request, Merchant $merchant): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu');
        }

        if ((int) $merchant->user_id !== (int) $user->id && !$user->hasRole('admin')) {
            abort(403, 'Akses ditolak. Anda bukan pemilik toko ini.');
        }
    }
}
