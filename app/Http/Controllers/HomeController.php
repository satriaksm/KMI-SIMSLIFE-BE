<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\Jasa;
use App\Models\Order;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Get recommended merchants for homepage
     * Typically the most active/top-rated merchants
     */
    public function recommendedMerchants(Request $request)
    {
        $limit = $request->get('limit', 6);

        $merchants = Merchant::where('status', 'approved')
            ->withCount('products')
            ->withCount('jasas')
            ->with('user')
            ->limit($limit)
            ->get()
            ->map(function ($merchant) {
                return [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'slug' => $merchant->slug,
                    'description' => $merchant->description,
                    'logo_path' => $merchant->logo_path,
                    'cover_path' => $merchant->cover_path,
                    'rating' => $merchant->rating ?? 0,
                    'products_count' => $merchant->products_count ?? 0,
                    'jasas_count' => $merchant->jasas_count ?? 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $merchants,
        ]);
    }

    /**
     * Get merchants for map carousel on homepage
     */
    public function mapCarouselMerchants(Request $request)
    {
        $limit = $request->get('limit', 10);

        $merchants = Merchant::where('status', 'approved')
            ->with('User', 'primaryAddress')
            ->limit($limit)
            ->get()
            ->map(function ($merchant) {
                $address = $merchant->primaryAddress;
                return [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'slug' => $merchant->slug,
                    'latitude' => $address ? (float) $address->latitude : null,
                    'longitude' => $address ? (float) $address->longitude : null,
                    'logo_path' => $merchant->logo_path,
                    'rating' => $merchant->rating ?? 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $merchants,
        ]);
    }

    /**
     * Get homepage statistics
     * Total products, merchants, jasa, etc.
     */
    public function statistics()
    {
        $stats = [
            'total_merchants' => Merchant::where('status', 'approved')->count(),
            'total_products' => Product::where('status', 'active')->count(),
            'total_jasas' => Jasa::where('is_active', true)->count(),
            'total_orders' => Order::count(),
            'total_customers' => \App\Models\User::whereHas('roles', function($q) {
                $q->where('name', 'customer');
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
