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
            $published = Jasa::where('merchant_id', $merchantId)->where('status', 'published')->count();
            $draft = Jasa::where('merchant_id', $merchantId)->where('status', 'draft')->count();
            $archived = Jasa::where('merchant_id', $merchantId)->where('status', 'archived')->count();
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
            $outOfStock = 0;
        } else {
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
         * STAT PESANAN & KEUANGAN
         * =========================
         */
        $ordersToday = \App\Models\Order::where('merchant_id', $merchantId)
            ->whereDate('created_at', today())
            ->whereIn('status', [
                'responsed', 'accepted', 'rejected', 'cancelled',
                'ready_to_pickup', 'delivered', 'completed', 
                'undelivered', 'unpicked'
            ])
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

        /**
         * =========================
         * CHART PESANAN 7 HARI TERAKHIR
         * =========================
         */
        $ordersChartLabels = [];
        $ordersChartData = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $ordersChartLabels[] = $date->translatedFormat('d M');
            $count = \App\Models\Order::where('merchant_id', $merchantId)
                ->whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();
            $ordersChartData[] = $count;
        }

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
                    'out_of_stock' => $outOfStock,
                ],
                'charts' => [
                    'orders' => [
                        'labels' => $ordersChartLabels,
                        'data' => $ordersChartData,
                    ]
                ]
            ],
            'Merchant dashboard data retrieved successfully.',
            200
        );
    }
}
