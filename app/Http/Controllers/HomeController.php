<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Category;
use App\Models\Jasa;
use App\Models\PaymentFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    /**
     * Get recommended merchants for homepage
     */
    public function recommendedMerchants(Request $request)
    {
        try {
            $limit = (int) $request->input('limit', 10);
            
            // Komposisi: 60% Top, 20% New, 20% Random
            $topLimit = (int) ceil($limit * 0.6);
            $newLimit = (int) round($limit * 0.2);
            $randomLimit = $limit - $topLimit - $newLimit;

            $baseQuery = Merchant::query()
                ->where('merchants.status', 'approved')
                ->with(['segmentation', 'primaryAddress.village', 'primaryAddress.district', 'primaryAddress.city', 'primaryAddress.province'])
                ->whereHas('primaryAddress', function ($query) {
                    $query->whereNotNull('latitude')
                        ->whereNotNull('longitude');
                })
                ->withCount([
                    'products' => function ($q) {
                        $q->where('status', 'published');
                    },
                    'jasas' => function ($q) {
                        $q->where('status', 'published');
                    },
                    'orders' => function ($q) {
                        $q->where('status', 'completed');
                    }
                ]);

            // 1. Top Merchants (Berdasarkan jumlah transaksi sukses & kelengkapan katalog)
            $topMerchants = (clone $baseQuery)
                ->orderByDesc('orders_count')
                ->orderByRaw('(products_count + jasas_count) DESC')
                ->limit($topLimit)
                ->get();

            $existingIds = $topMerchants->pluck('id')->toArray();

            // 2. New & Trending (Baru bergabung dalam 30 hari terakhir, punya produk)
            $newMerchants = (clone $baseQuery)
                ->whereNotIn('merchants.id', $existingIds)
                ->where('merchants.created_at', '>=', now()->subDays(30))
                ->orderByRaw('(products_count + jasas_count) DESC')
                ->limit($newLimit)
                ->get();

            $existingIds = array_merge($existingIds, $newMerchants->pluck('id')->toArray());

            // 3. Random (Sisanya, menghindari UMKM pasif yang tidak punya produk/jasa)
            $randomLimitActual = $limit - count($existingIds);
            $randomMerchants = (clone $baseQuery)
                ->whereNotIn('merchants.id', $existingIds)
                ->havingRaw('(products_count + jasas_count) > 0') // Filter UMKM pasif
                ->inRandomOrder()
                ->limit($randomLimitActual > 0 ? $randomLimitActual : 0)
                ->get();

            // Gabungkan hasil dan petakan koordinat
            $merchants = $topMerchants
                ->concat($newMerchants)
                ->concat($randomMerchants)
                ->map(function ($merchant) {
                    $address = $merchant->primaryAddress;
                    if ($address) {
                        $merchant->latitude = $address->latitude;
                        $merchant->longitude = $address->longitude;
                    }
                    return $merchant;
                });

            return response()->json([
                'data' => $merchants,
                'total' => $merchants->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[HomeController] Failed to get recommended merchants', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load recommended merchants',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get merchants for map carousel
     */
    public function mapCarouselMerchants(Request $request)
    {
        try {
            $limit = $request->input('limit') ? (int) $request->input('limit') : null;

            $query = Merchant::query()
                ->where('merchants.status', 'approved')
                ->with(['segmentation', 'primaryAddress.village', 'primaryAddress.district', 'primaryAddress.city', 'primaryAddress.province'])
                ->withCount([
                    'products' => function ($q) {
                        $q->where('status', 'published');
                    },
                    'jasas' => function ($q) {
                        $q->where('status', 'published');
                    }
                ])

                ->whereHas('primaryAddress', function ($query) {
                    $query->whereNotNull('latitude')
                        ->whereNotNull('longitude');
                })
                ->orderByRaw('(products_count + jasas_count) DESC')
                ->latest();

            if ($limit) {
                $query->limit($limit);
            }

            $merchants = $query->get()->map(function ($merchant) {
                $address = $merchant->primaryAddress;
                if ($address) {
                    $merchant->latitude = $address->latitude;
                    $merchant->longitude = $address->longitude;
                }
                return $merchant;
            });

            return response()->json(['data' => $merchants]);
        } catch (\Exception $e) {
            Log::error('[HomeController] Failed to get map carousel merchants', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load merchants for map',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get homepage statistics
     */
    public function statistics()
    {
        try {
            $totalProducts = Product::where('status', 'published')->count();
            $totalJasas = Jasa::where('status', 'published')->count();

            $stats = [
                'total_merchants' => Merchant::where('status', 'approved')->count(),
                'total_products' => $totalProducts + $totalJasas,
                'total_categories' => Category::whereNull('parent_id')->count(),
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            Log::error('[HomeController] Failed to get statistics', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get public payment fees for checkout display
     */
    public function paymentFees()
    {
        try {
            $fees = PaymentFee::where('is_active', true)
                ->where('method_code', '!=', 'PAYOUT')
                ->get()
                ->map(function ($fee) {
                    return [
                        'method_code' => $fee->method_code,
                        'method_name' => $fee->method_name,
                        'type' => $fee->type,
                        'value' => (float) $fee->value,
                        'description' => $fee->description,
                    ];
                });

            return response()->json(['data' => $fees]);
        } catch (\Exception $e) {
            Log::error('[HomeController] Failed to get payment fees', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load payment fees',
            ], 500);
        }
    }
}
