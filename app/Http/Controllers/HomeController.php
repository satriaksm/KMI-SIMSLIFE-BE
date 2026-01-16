<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Category;
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
            $limit = $request->input('limit', 10);

            $merchants = Merchant::query()
                ->where('merchants.status', 'approved')
                ->with(['segmentation', 'paguyuban', 'primaryAddress'])
                ->whereHas('primaryAddress', function ($query) {
                    $query->whereNotNull('latitude')
                          ->whereNotNull('longitude');
                })
                ->withCount(['products' => function ($q) {
                    $q->where('status', 'published');
                }])
                ->inRandomOrder()
                ->limit($limit)
                ->get()
                ->map(function ($merchant) {
                    // Append coordinates dari primaryAddress ke merchant object
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
     * ✅ UPDATED: Handle optional limit parameter
     */
    public function mapCarouselMerchants(Request $request)
    {
        try {
            // ✅ Jika tidak ada limit, tampilkan semua (atau set default tinggi)
            $limit = $request->input('limit') ? (int)$request->input('limit') : null;

            $query = Merchant::query()
                ->where('merchants.status', 'approved')
                ->with(['segmentation', 'primaryAddress'])
                ->whereHas('primaryAddress', function ($query) {
                    $query->whereNotNull('latitude')
                          ->whereNotNull('longitude');
                })
                ->inRandomOrder();

            // ✅ Hanya apply limit jika ada
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
            $stats = [
                'total_merchants' => Merchant::where('status', 'approved')->count(),
                'total_products' => Product::where('status', 'published')->count(),
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
}
