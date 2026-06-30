<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Address;
use App\Models\Merchant;
use App\Models\ShippingSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShippingController extends Controller
{
    /**
     * GET /api/shipping/settings
     * Return active shipping settings (public).
     */
    public function settings()
    {
        $setting = ShippingSetting::query()
            ->where('status', 'active')
            ->first();

        if (!$setting) {
            return ApiResponse::success([
                'base_cost' => 15000,
                'cost_per_km' => 5000,
            ], 'Using default active shipping setting');
        }

        return ApiResponse::success([
            'base_cost' => (float) $setting->base_cost,
            'cost_per_km' => (float) $setting->cost_per_km,
        ], 'Shipping settings fetched');
    }

    /**
     * POST /api/shipping/calculate
     * Calculate shipping cost between a merchant and a customer address.
     *
     * Required: merchant_id (int)
     * Optional: address_id (int) — if omitted, uses user's primary address.
     *
     * Returns: { delivery_fee, distance_km, base_cost, cost_per_km }
     */
    public function calculate(Request $request)
    {
        $request->validate([
            'merchant_id' => 'required|integer|exists:merchants,id',
            'address_id' => 'nullable|integer|exists:addresses,id',
        ]);

        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        // Get merchant address
        $merchant = Merchant::query()->findOrFail($request->integer('merchant_id'));
        $merchantAddress = $merchant->primaryAddress()->first();

        if (!$merchantAddress || !$merchantAddress->latitude || !$merchantAddress->longitude) {
            return ApiResponse::success([
                'delivery_fee' => 0,
                'distance_km' => 0,
                'base_cost' => 0,
                'cost_per_km' => 0,
                'note' => 'Alamat merchant belum tersedia',
            ], 'Shipping cost calculated');
        }

        // Get customer address
        $customerAddress = null;
        $userAddressableTypes = array_values(array_unique([
            $user->getMorphClass(),
            get_class($user),
        ]));

        if ($request->filled('address_id')) {
            $customerAddress = Address::query()
                ->where('id', $request->integer('address_id'))
                ->where('addressable_id', $user->id)
                ->whereIn('addressable_type', $userAddressableTypes)
                ->first();
        } else {
            $customerAddress = $user->primaryAddress()->first();
        }

        if (!$customerAddress || !$customerAddress->latitude || !$customerAddress->longitude) {
            return ApiResponse::success([
                'delivery_fee' => 0,
                'distance_km' => 0,
                'base_cost' => 0,
                'cost_per_km' => 0,
                'note' => 'Alamat pelanggan belum tersedia',
            ], 'Shipping cost calculated');
        }

        $setting = ShippingSetting::query()
            ->where('status', 'active')
            ->first();

        $baseCost = $setting ? (float) $setting->base_cost : 15000;
        $costPerKm = $setting ? (float) $setting->cost_per_km : 5000;

        $distanceKm = self::haversineDistance(
            (float) $merchantAddress->latitude,
            (float) $merchantAddress->longitude,
            (float) $customerAddress->latitude,
            (float) $customerAddress->longitude,
        );

        $deliveryFee = $baseCost + ($costPerKm * $distanceKm);

        // Round up to nearest 500
        $deliveryFee = ceil($deliveryFee / 500) * 500;

        return ApiResponse::success([
            'delivery_fee' => (int) $deliveryFee,
            'distance_km' => round($distanceKm, 2),
            'base_cost' => $baseCost,
            'cost_per_km' => $costPerKm,
        ], 'Shipping cost calculated');
    }

    /**
     * Calculate Haversine distance between two lat/lng coords (in km).
     */
    public static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
