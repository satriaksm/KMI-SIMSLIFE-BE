<?php
namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VoucherController extends Controller
{
    /**
     * Get available vouchers for user
     * Public or authenticated
     */
    public function index(Request $request)
    {
        $query = Voucher::with(['merchant:id,name,logo_path', 'event:id,event_name'])
            ->active();

        // Filter by merchant
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        // Filter by event
        if ($request->has('event_id')) {
            $query->where('event_id', $request->event_id);
        }

        $vouchers = $query->paginate($request->input('per_page', 15));

        return response()->json($vouchers);
    }

    /**
     * Validate voucher codedxxzx
     * POST /api/vouchers/validate
     */
    public function validateVoucher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voucher_code' => 'required|string|exists:vouchers,voucher_code',
            'order_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid voucher code',
            ], 422);
        }

        $voucher = Voucher::where('voucher_code', $request->voucher_code)
            ->active()
            ->first();

        if (!$voucher) {
            return response()->json([
                'valid' => false,
                'message' => 'Voucher expired or inactive',
            ], 422);
        }

        // Check minimum purchase
        if ($request->order_amount < $voucher->min_purchase_amount) {
            return response()->json([
                'valid' => false,
                'message' => "Minimum purchase amount is Rp " . number_format($voucher->min_purchase_amount, 0, ',', '.'),
            ], 422);
        }

        // Check usage limit
        if ($voucher->usage_limit) {
            $totalUsage = VoucherUsage::where('voucher_id', $voucher->id)->count();
            if ($totalUsage >= $voucher->usage_limit) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Voucher usage limit reached',
                ], 422);
            }
        }

        // Check user usage limit
        if ($request->user()) {
            $canUse = VoucherUsage::canUseVoucher(
                $request->user()->id,
                $voucher->id,
                $voucher
            );

            if (!$canUse) {
                return response()->json([
                    'valid' => false,
                    'message' => 'You have reached the usage limit for this voucher',
                ], 422);
            }
        }

        // Calculate discount
        $discount = $this->calculateDiscount($voucher, $request->order_amount);

        return response()->json([
            'valid' => true,
            'voucher' => $voucher,
            'discount_amount' => $discount,
            'final_amount' => max(0, $request->order_amount - $discount),
        ]);
    }

    /**
     * Calculate discount amount
     */
    private function calculateDiscount(Voucher $voucher, float $orderAmount): float
    {
        if ($voucher->voucher_type === 'fixed') {
            return min($voucher->value, $orderAmount);
        }

        // Percent type
        $discount = ($orderAmount * $voucher->value) / 100;

        // Apply max discount cap if set
        if ($voucher->max_discount_amount) {
            $discount = min($discount, $voucher->max_discount_amount);
        }

        return $discount;
    }

    /**
     * MERCHANT: Create voucher
     */
    public function store(Request $request)
    {
        $merchant = $request->user()->merchants()->first();

        if (!$merchant) {
            return response()->json(['message' => 'Merchant not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'voucher_code' => 'required|string|max:100|unique:vouchers,voucher_code',
            'voucher_type' => 'required|in:percent,fixed',
            'voucher_description' => 'nullable|string',
            'value' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_purchase_amount' => 'nullable|numeric|min:0',
            'voucher_start_date' => 'required|date',
            'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
            'usage_limit_per_user' => 'required|integer|min:1',
            'usage_limit' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $voucher = $merchant->vouchers()->create([
            'voucher_code' => strtoupper($request->voucher_code),
            'voucher_type' => $request->voucher_type,
            'voucher_description' => $request->voucher_description,
            'voucher_status' => 'active',
            'value' => $request->value,
            'max_discount_amount' => $request->max_discount_amount,
            'min_purchase_amount' => $request->min_purchase_amount ?? 0,
            'voucher_start_date' => $request->voucher_start_date,
            'voucher_end_date' => $request->voucher_end_date,
            'usage_limit_per_user' => $request->usage_limit_per_user,
            'usage_limit' => $request->usage_limit,
        ]);

        return response()->json([
            'message' => 'Voucher created successfully',
            'data' => $voucher,
        ], 201);
    }
}