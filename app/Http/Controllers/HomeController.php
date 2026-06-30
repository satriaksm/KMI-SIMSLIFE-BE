<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Category;
use App\Models\Jasa;
use App\Models\PaymentFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    /**
     * Get recommended merchants for homepage
     */
    public function recommendedMerchants(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);

            $merchants = Merchant::query()
                ->where('merchants.status', 'approved')
                ->with([
                    'segmentation',
                    'paguyuban',
                    'primaryAddress',
                    'primaryAddress.village:id,name',
                    'primaryAddress.district:id,name',
                    'primaryAddress.city:id,name',
                    'primaryAddress.province:id,name',
                ])
                ->whereHas('primaryAddress', function ($query) {
                    $query->whereNotNull('latitude')
                        ->whereNotNull('longitude');
                })
                ->withCount([
                    'products' => function ($q) {
                        $q->where('status', 'published');
                    },
                    'jasas' => function ($q) {
                        // Count jasas where is_active=true
                        $q->where('is_active', true);
                    }
                ])
                ->inRandomOrder()
                ->limit($limit)
                ->get()
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
                ->with([
                    'segmentation',
                    'primaryAddress',
                    'primaryAddress.village:id,name',
                    'primaryAddress.district:id,name',
                    'primaryAddress.city:id,name',
                    'primaryAddress.province:id,name',
                ])
                ->whereNotNull('logo_path')
                ->whereNotNull('cover_path')
                ->whereHas('primaryAddress', function ($query) {
                    $query->whereNotNull('latitude')
                        ->whereNotNull('longitude');
                })
                ->inRandomOrder();

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
            $totalJasas = Jasa::where('is_active', true)->count();

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
     * Get active payment fees for frontend checkout.
     * Endpoint: GET /api/public/home/payment-fees
     */
    public function paymentFees()
    {
        try {
            $fees = PaymentFee::where('is_active', true)
                ->where('method_code', '!=', 'PAYOUT')
                ->orderBy('method_code')
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

            return response()->json([
                'data' => $fees,
            ]);
        } catch (\Exception $e) {
            Log::error('[HomeController] Failed to get payment fees', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load payment fees',
                'data' => [],
            ], 500);
        }
    }
}
