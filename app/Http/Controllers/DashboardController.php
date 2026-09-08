<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Jasa;
use App\Models\Voucher;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function merchantDashboard(Request $request, Merchant $merchant)
    {
        $user = $request->user();

        // 🔐 Validasi kepemilikan merchant (berdasarkan merchant hasil binding by slug)
        if ((int) $merchant->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke merchant ini'
            ], 403);
        }

        $merchantId = $merchant->id;
        $isJasaMerchant = (int) $merchant->segmentation_id === 3;

        /**
         * =========================
         * STAT REKAP PESANAN & KEUANGAN
         * =========================
         */
        $allOrders = Order::where('merchant_id', $merchantId);
        $totalOrders = (clone $allOrders)->count();
        $pendingOrders = (clone $allOrders)->whereIn('status', ['pending', 'paid', 'waiting_review'])->count();
        $processingOrders = (clone $allOrders)->whereIn('status', ['processing', 'responsed', 'delivered', 'proses'])->count();
        $completedOrders = (clone $allOrders)->whereIn('status', ['completed', 'selesai'])->count();
        $cancelledOrders = (clone $allOrders)->whereIn('status', ['cancelled', 'rejected', 'undelivered', 'unpicked', 'batal'])->count();
        $todayOrders = (clone $allOrders)->whereDate('created_at', Carbon::today())->count();

        // Rekap Pendapatan
        $totalRevenue = (float) Order::where('merchant_id', $merchantId)->whereIn('status', ['completed', 'selesai'])->sum('total');
        $todayRevenue = (float) Order::where('merchant_id', $merchantId)->whereIn('status', ['completed', 'selesai'])->whereDate('created_at', Carbon::today())->sum('total');
        $thisMonthRevenue = (float) Order::where('merchant_id', $merchantId)->whereIn('status', ['completed', 'selesai'])->whereMonth('created_at', Carbon::now()->month)->whereYear('created_at', Carbon::now()->year)->sum('total');
        $pendingRevenue = (float) Order::where('merchant_id', $merchantId)->whereIn('status', ['processing', 'responsed', 'delivered', 'proses', 'waiting_review'])->sum('total');

        /**
         * =========================
         * STAT KATALOG (Produk / Jasa)
         * =========================
         */
        if ($isJasaMerchant) {
            $totalProducts = Jasa::where('merchant_id', $merchantId)->count();
            $published = Jasa::where('merchant_id', $merchantId)->where('is_active', true)->count();
            $draft = Jasa::where('merchant_id', $merchantId)->where('is_active', false)->count();
            $archived = 0;
        } else {
            $totalProducts = Product::where('merchant_id', $merchantId)->count();
            $published = Product::where('merchant_id', $merchantId)->published()->count();
            $draft = Product::where('merchant_id', $merchantId)->draft()->count();
            $archived = Product::where('merchant_id', $merchantId)->archived()->count();
        }

        /**
         * =========================
         * STOK (VARIANT-BASED)
         * =========================
         */
        if ($isJasaMerchant) {
            $lowStock = 0;
            $outOfStock = 0;
        } else {
            $lowStock = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.merchant_id', $merchantId)
                ->where('products.status', 'published')
                ->whereBetween('product_variants.stock', [1, 5])
                ->distinct('products.id')
                ->count('products.id');

            $outOfStock = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.merchant_id', $merchantId)
                ->where('products.status', 'published')
                ->where('product_variants.stock', 0)
                ->distinct('products.id')
                ->count('products.id');
        }

        /**
         * =========================
         * STAT VOUCHER
         * =========================
         */
        $totalVouchers = Voucher::where('merchant_id', $merchantId)->count();
        $activeVouchers = Voucher::where('merchant_id', $merchantId)->active()->count();
        $inactiveVouchers = Voucher::where('merchant_id', $merchantId)
            ->where('voucher_status', 'inactive')
            ->count();
        $expiredVouchers = Voucher::where('merchant_id', $merchantId)
            ->whereDate('voucher_end_date', '<', now())
            ->count();

        $voucherUsedCount = DB::table('voucher_usages')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_usages.voucher_id')
            ->leftJoin('orders', 'orders.id', '=', 'voucher_usages.order_id')
            ->where('vouchers.merchant_id', $merchantId)
            ->where(function ($q) {
                $q->whereNull('voucher_usages.order_id')
                  ->orWhereIn('orders.status', ['completed', 'selesai']);
            })
            ->count();

        /**
         * =========================
         * RECENT ORDERS (5 Transaksi Terakhir)
         * =========================
         */
        $recentOrders = Order::where('merchant_id', $merchantId)
            ->with(['items.product', 'user'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($order) {
                $isCancelled = in_array($order->status, ['cancelled', 'batal', 'rejected', 'gagal', 'undelivered', 'unpicked'], true);
                $firstItem = $order->items->first();
                $firstItemName = $firstItem?->product?->name ?? ($order->order_type === 'jasa' ? 'Layanan Jasa' : 'Item');
                return [
                    'id' => $order->id,
                    'order_code' => $order->order_code ?: ('ORD-' . $order->id),
                    'order_type' => $order->order_type ?: ($order->jasa_id ? 'jasa' : 'product'),
                    'customer_name' => $order->nama ?: ($order->user?->name ?? 'Pelanggan'),
                    'delivery_type' => $isCancelled ? '-' : ($order->delivery_type === 'delivery' ? 'Kirim' : 'Ambil Sendiri'),
                    'status' => in_array($order->status, ['selesai'], true) ? 'completed' : (in_array($order->status, ['batal'], true) ? 'cancelled' : $order->status),
                    'total' => (float) $order->total,
                    'created_at' => $order->created_at?->toIso8601String(),
                    'items_count' => $order->items->sum('quantity') ?: $order->items->count(),
                    'first_item_name' => $firstItemName,
                ];
            });

        /**
         * =========================
         * CHART PESANAN & PENDAPATAN 7 HARI TERAKHIR
         * =========================
         */
        $chartDates = [];
        $chartOrdersData = [];
        $chartRevenueData = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dateStr = $date->toDateString();
            $chartDates[] = $date->isoFormat('D MMM');

            $dayOrdersCount = Order::where('merchant_id', $merchantId)
                ->whereDate('created_at', $dateStr)
                ->count();

            $dayRevenueSum = (float) Order::where('merchant_id', $merchantId)
                ->whereIn('status', ['completed', 'selesai'])
                ->whereDate('created_at', $dateStr)
                ->sum('total');

            $chartOrdersData[] = $dayOrdersCount;
            $chartRevenueData[] = $dayRevenueSum;
        }

        return ApiResponse::success(
            [
                'merchant' => [
                    'id' => $merchant->id,
                    'slug' => $merchant->slug,
                    'name' => $merchant->name,
                ],
                'order_stats' => [
                    'total' => $totalOrders,
                    'pending' => $pendingOrders,
                    'processing' => $processingOrders,
                    'completed' => $completedOrders,
                    'cancelled' => $cancelledOrders,
                    'today' => $todayOrders,
                ],
                'revenue_stats' => [
                    'total_revenue' => $totalRevenue,
                    'today_revenue' => $todayRevenue,
                    'this_month_revenue' => $thisMonthRevenue,
                    'pending_revenue' => $pendingRevenue,
                ],
                'stats' => [
                    'total' => $totalProducts,
                    'published' => $published,
                    'draft' => $draft,
                    'archived' => $archived,
                    'low_stock' => $lowStock,
                    'out_of_stock' => $outOfStock,
                ],
                'voucher_stats' => [
                    'total' => $totalVouchers,
                    'active' => $activeVouchers,
                    'inactive' => $inactiveVouchers,
                    'expired' => $expiredVouchers,
                    'used' => $voucherUsedCount,
                ],
                'recent_orders' => $recentOrders,
                'charts' => [
                    'orders' => [
                        'labels' => $chartDates,
                        'data' => $chartOrdersData,
                        'revenue_data' => $chartRevenueData,
                    ],
                ]
            ],
            'Merchant dashboard data retrieved successfully.',
            200
        );
    }
}
