<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Jasa;
use App\Models\Voucher;
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
            ->where('vouchers.merchant_id', $merchantId)
            ->count();

        /**
         * =========================
         * CHART KATEGORI (TOP 3 + LAINNYA)
         * =========================
         * - Untuk merchant produk: pakai relasi products
         * - Untuk merchant jasa: pakai relasi jasas
         */
        if ($isJasaMerchant) {
            $categoryStats = Category::whereHas('jasas', function ($q) use ($merchantId) {
                $q->where('merchant_id', $merchantId)
                    ->where('is_active', true);
            })
                ->withCount([
                    'jasas as total' => function ($q) use ($merchantId) {
                        $q->where('merchant_id', $merchantId)
                            ->where('is_active', true);
                    }
                ])
                ->orderByDesc('total')
                ->get();
        } else {
            $categoryStats = Category::whereHas('products', function ($q) use ($merchantId) {
                $q->where('merchant_id', $merchantId)
                    ->where('status', 'published');
            })
                ->withCount([
                    'products as total' => function ($q) use ($merchantId) {
                        $q->where('merchant_id', $merchantId)
                            ->where('status', 'published');
                    }
                ])
                ->orderByDesc('total')
                ->get();
        }

        $topCategories = $categoryStats->take(3);
        $otherTotal = $categoryStats->slice(3)->sum('total');

        $labels = $topCategories->pluck('name')->toArray();
        $data = $topCategories->pluck('total')->toArray();

        if ($otherTotal > 0) {
            $labels[] = 'Lainnya';
            $data[] = $otherTotal;
        }

        /**
         * =========================
         * STAT PESANAN & KEUANGAN
         * =========================
         */
        $ordersToday = \App\Models\Order::where('merchant_id', $merchantId)
            ->whereDate('created_at', today())
            ->where(function ($q) {
                $q->where('status', '!=', 'pending')
                  ->orWhere(function ($sq) {
                      $sq->where('status', 'pending')->where('payment_method', 'COD');
                  });
            })
            ->count();

        $ordersPending = \App\Models\Order::where('merchant_id', $merchantId)
            ->where(function ($q) {
                $q->where('status', 'paid')
                  ->orWhere(function ($sq) {
                      $sq->where('status', 'pending')->where('payment_method', 'COD');
                  });
            })->count();

        $ordersCompleted = \App\Models\Order::where('merchant_id', $merchantId)
            ->where('status', 'completed')
            ->count();

        $revenueTotal = \App\Models\Order::where('merchant_id', $merchantId)
            ->where('status', 'completed')
            ->sum('net_amount');

        return ApiResponse::success(
            [
                'merchant' => [
                    'id' => $merchant->id,
                    'slug' => $merchant->slug,
                    'name' => $merchant->name,
                ],
                'wallet' => [
                    'balance_available'    => (float) $merchant->balance_available,
                    'balance_pending'      => (float) $merchant->balance_pending,
                    'balance_held'         => (float) $merchant->balance_held,
                    'balance_withdrawable' => (float) $merchant->balance_withdrawable,
                ],
                'order_stats' => [
                    'today'      => $ordersToday,
                    'pending'    => $ordersPending,
                    'completed'  => $ordersCompleted,
                    'revenue'    => (float) $revenueTotal,
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
                'charts' => [
                    'status' => [
                        'labels' => ['Published', 'Draft', 'Archived'],
                        'data' => [$published, $draft, $archived],
                    ],
                    'category' => [
                        'labels' => $labels,
                        'datasets' => [
                            ['data' => $data]
                        ]
                    ]
                ]
            ],
            'Merchant dashboard data retrieved successfully.',
            200
        );
    }
}
