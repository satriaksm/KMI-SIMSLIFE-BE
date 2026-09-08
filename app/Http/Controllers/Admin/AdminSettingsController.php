<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ShippingSetting;
use App\Models\PaymentFee;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    /**
     * GET /api/admin/settings/shipping
     * Return the active shipping settings.
     */
    public function shippingIndex()
    {
        $setting = ShippingSetting::query()
            ->where('status', 'active')
            ->first();

        if (!$setting) {
            return ApiResponse::success([
                'id' => null,
                'base_cost' => 0,
                'cost_per_km' => 0,
                'status' => 'active',
            ], 'No active shipping setting');
        }

        return ApiResponse::success($setting, 'Shipping settings fetched');
    }

    /**
     * PUT /api/admin/settings/shipping
     * Update or create the active shipping setting.
     */
    public function shippingUpdate(Request $request)
    {
        $request->validate([
            'base_cost' => 'required|numeric|min:0',
            'cost_per_km' => 'required|numeric|min:0',
        ]);

        $setting = ShippingSetting::query()
            ->where('status', 'active')
            ->first();

        if ($setting) {
            $setting->update([
                'base_cost' => $request->input('base_cost'),
                'cost_per_km' => $request->input('cost_per_km'),
            ]);
        } else {
            $setting = ShippingSetting::create([
                'base_cost' => $request->input('base_cost'),
                'cost_per_km' => $request->input('cost_per_km'),
                'status' => 'active',
            ]);
        }

        return ApiResponse::success($setting, 'Shipping settings updated');
    }

    /**
     * GET /api/admin/settings/payment-fees
     * Return the current payment fee configuration.
     * For now these are hardcoded in OrderController — this endpoint just returns
     * them so the admin panel can display them.
     */
    public function paymentFeesIndex()
    {
        $fees = PaymentFee::all()->map(function ($fee) {
            return [
                'id' => $fee->id,
                'method_code' => $fee->method_code,
                'method' => $fee->method_name,
                'type' => $fee->type,
                'value' => (float)$fee->value,
                'display' => $fee->display,
                'description' => $fee->description,
                'is_active' => $fee->is_active,
            ];
        });

        return ApiResponse::success([
            'fees' => $fees,
            'note' => 'Admin dapat merubah fee ini. Pastikan fee sesuai dengan ketentuan Xendit + PPN 11%.',
            'source' => 'Database',
        ], 'Payment fees fetched');
    }

    /**
     * PUT /api/admin/settings/payment-fees/{id}
     */
    public function paymentFeeUpdate(Request $request, $id)
    {
        $request->validate([
            'type' => 'required|in:percentage,flat',
            'value' => 'required|numeric|min:0',
        ]);

        $fee = PaymentFee::findOrFail($id);
        $fee->update([
            'type' => $request->input('type'),
            'value' => $request->input('value'),
        ]);

        return ApiResponse::success($fee, 'Payment fee updated');
    }
}
