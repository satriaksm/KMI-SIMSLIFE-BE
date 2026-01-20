<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\Merchant;
use App\Helpers\ApiResponse;
use App\Models\VoucherUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

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
            $merchantId = (int) $request->merchant_id;

            $acceptedEventIds = DB::table('event_merchants')
                ->select('event_id')
                ->where('merchant_id', $merchantId)
                ->where('status', 'accepted');

            $query->where(function ($q) use ($merchantId, $acceptedEventIds) {
                $q->where('merchant_id', $merchantId)
                    ->orWhereIn('event_id', $acceptedEventIds);
            });
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
                'message' => "Minimum purchase amount is Rp " . number_format((float) $voucher->min_purchase_amount, 0, ',', '.'),
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

    /**
     * ADMIN: List all vouchers (from merchants and events)
     */
    public function adminIndex(Request $request)
    {
        Log::info('[VoucherController] adminIndex called', [
            'params' => $request->all()
        ]);

        $query = Voucher::with([
            'merchant:id,name,logo_path',
            'event:id,event_name',
            'usages',
        ])
            ->withCount('usages');

        // Filter by status
        if ($request->has('voucher_status')) {
            $query->where('voucher_status', $request->voucher_status);
        }

        // Filter by type
        if ($request->has('voucher_type')) {
            $query->where('voucher_type', $request->voucher_type);
        }

        // Filter by merchant
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('voucher_code', 'like', "%{$search}%")
                    ->orWhere('voucher_description', 'like', "%{$search}%");
            });
        }

        $vouchers = $query->latest()
            ->paginate($request->input('per_page', 15));

        Log::info('[VoucherController] Returning vouchers', [
            'count' => $vouchers->count(),
            'total' => $vouchers->total()
        ]);

        return response()->json($vouchers);
    }

    /**
     * ADMIN: Get single voucher detail
     */
    public function adminShow($id)
    {
        $voucher = Voucher::with([
            'merchant.user',
            'event',
            'usages.user',
        ])
            ->withCount('usages')
            ->findOrFail($id);

        return response()->json(['data' => $voucher]);
    }

    /**
     * ADMIN: Delete voucher (only if not used)
     */
    public function destroy($id)
    {
        $voucher = Voucher::withCount('usages')->findOrFail($id);

        if ($voucher->usages_count > 0) {
            return response()->json([
                'message' => 'Cannot delete voucher that has been used',
            ], 422);
        }

        $voucher->delete();

        return response()->json([
            'message' => 'Voucher deleted successfully'
        ]);
    }

    public function merchantStore(Request $request, Merchant $merchant)
    {
        $this->authorize('manageMerchant', [Voucher::class, $merchant]);

        $data = $request->validate([
            'voucher_name' => 'required|string|max:100',
            'voucher_code' => 'required|string|max:100|unique:vouchers,voucher_code',
            'voucher_description' => 'nullable|string',
            'voucher_type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'voucher_start_date' => 'required|date',
            'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_purchase_amount' => 'nullable|numeric|min:0',
            'usage_limit_per_user' => 'required|integer|min:1',
            'usage_limit' => 'nullable|integer|min:1',
        ]);

        return $merchant->vouchers()->create($data);
    }

    public function merchantIndex(Request $request, Merchant $merchant)
    {
        $this->authorize('manageMerchant', [Voucher::class, $merchant]);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'type' => ['nullable', 'in:percent,fixed'],
            'is_expired' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'sort_by' => ['nullable', 'in:newest,oldest,name_asc,name_desc,value_asc,value_desc,usage_asc,usage_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $merchant->vouchers()
            ->with('event:id,event_name')
            ->withCount('usages');

        if (!empty($data['q'])) {
            $q = $data['q'];

            $query->where(function ($sub) use ($q) {
                $sub->where('voucher_name', 'like', "%{$q}%")
                    ->orWhere('voucher_code', 'like', "%{$q}%")
                    ->orWhereHas('event', function ($event) use ($q) {
                        $event->where('event_name', 'like', "%{$q}%");
                    });
            });
        }
        if (!empty($data['status'])) {
            $query->where('voucher_status', $data['status']);
        }

        if (!empty($data['type'])) {
            $query->where('voucher_type', $data['type']);
        }
        if (array_key_exists('is_expired', $data)) {
            if ($data['is_expired']) {
                $query->whereDate('voucher_end_date', '<', now());
            } else {
                $query->whereDate('voucher_end_date', '>=', now());
            }
        }
        if (!empty($data['start_date'])) {
            $query->whereDate('voucher_start_date', '>=', $data['start_date']);
        }

        if (!empty($data['end_date'])) {
            $query->whereDate('voucher_end_date', '<=', $data['end_date']);
        }

        $sortBy = $data['sort_by'] ?? 'newest';

        switch ($sortBy) {
            case 'oldest':
                $query->orderBy('voucher_start_date', 'asc');
                break;

            case 'name_asc':
                $query->orderBy('voucher_name', 'asc');
                break;

            case 'name_desc':
                $query->orderBy('voucher_name', 'desc');
                break;

            case 'value_asc':
                $query->orderBy('value', 'asc');
                break;

            case 'value_desc':
                $query->orderBy('value', 'desc');
                break;

            case 'usage_asc':
                // usages_count dari withCount()
                $query->orderBy('usages_count', 'asc');
                break;

            case 'usage_desc':
                $query->orderBy('usages_count', 'desc');
                break;

            case 'newest':
            default:
                $query->orderBy('voucher_start_date', 'desc');
                break;
        }

        $vouchers = $query
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 10);

        $vouchers->getCollection()->transform(function ($voucher) {
            $voucher->usage = "{$voucher->usages_count} / {$voucher->usage_limit}";
            $voucher->is_expired = $voucher->voucher_end_date->isPast();

            $voucher->makeHidden([
                'created_at',
                'updated_at',
                'usage_limit',
                'max_discount_amount',
                'min_purchase_amount',
                'usage_limit_per_user',
                'voucher_description',
                'event_id',
            ]);

            return $voucher;
        });

        return ApiResponse::success(
            $vouchers->items(),
            'Daftar voucher tersedia',
            200,
            [
                'total' => $vouchers->total(),
                'per_page' => $vouchers->perPage(),
                'current_page' => $vouchers->currentPage(),
                'last_page' => $vouchers->lastPage(),
            ]
        );
    }

    public function merchantShow(Merchant $merchant, Voucher $voucher)
    {
        $this->authorize('view', [$voucher, $merchant]);

        abort_if((int) $voucher->merchant_id !== (int) $merchant->id, 404);

        // Load relasi event dan count usages
        $voucher->load(['event:id,event_name']);
        $voucher->loadCount('usages');

        // Pastikan usages_count selalu integer (default 0)
        $usagesCount = $voucher->usages_count ?? 0;
        $voucher->usage = "{$usagesCount} / {$voucher->usage_limit}";
        $voucher->is_expired = $voucher->voucher_end_date
            ? Carbon::parse($voucher->voucher_end_date)->isPast()
            : false;

        $voucher->makeHidden([
            'created_at',
            'updated_at',
            'event_id',
            'usages_count'
        ]);

        return ApiResponse::success($voucher, 'Detail voucher berhasil dimuat.');
    }

    public function merchantUpdate(Request $request, Merchant $merchant, Voucher $voucher)
    {
        $this->authorize('update', [$voucher, $merchant]);

        abort_if((int) $voucher->merchant_id !== (int) $merchant->id, 404);

        $validated = $request->validate([
            'voucher_name' => 'required|string|max:255',
            'voucher_code' => 'required|string|max:255',
            'voucher_description' => 'required|string',
            'voucher_type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:1',
            'voucher_start_date' => 'required|date',
            'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
            'min_purchase_amount' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0|required_if:voucher_type,percent',
            'usage_limit_per_user' => 'required|integer|min:1',
            'usage_limit' => 'required|integer|min:0',
        ]);

        // Jika tidak dikirim, set null
        if (
            $validated['max_discount_amount'] === 0
        ) {
            $validated['max_discount_amount'] = null;
        }


        $voucher->update($validated);

        return ApiResponse::success($voucher->fresh(), 'Voucher berhasil diperbarui.');
    }

    public function merchantDestroy(Merchant $merchant, Voucher $voucher)
    {
        $this->authorize('delete', [$voucher, $merchant]);

        abort_if((int) $voucher->merchant_id !== (int) $merchant->id, 404);


        $voucher->delete();

        return ApiResponse::success(null, 'Voucher berhasil dihapus.');

    }

    public function updateStatus(
        Request $request,
        Merchant $merchant,
        Voucher $voucher
    ) {
        $this->authorize('update', [$voucher, $merchant]);

        $data = $request->validate([
            'voucher_status' => ['required', 'in:active,inactive'],
        ]);

        $voucher->update([
            'voucher_status' => $data['voucher_status'],
        ]);

        return ApiResponse::success($voucher->fresh(), 'Status voucher berhasil diperbarui.');
    }


    public function bulkDelete(Request $request, Merchant $merchant)
    {
        $this->authorize('manageMerchant', [Voucher::class, $merchant]);

        $data = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1'],
            'voucher_ids.*' => ['required', 'integer'],
        ]);

        $vouchers = Voucher::whereIn('id', $data['voucher_ids'])
            ->where('merchant_id', $merchant->id)
            ->get();

        if ($vouchers->isEmpty()) {
            return ApiResponse::error('Voucher tidak ditemukan', 404);
        }

        DB::transaction(function () use ($vouchers) {
            foreach ($vouchers as $voucher) {
                $voucher->delete();
            }
        });


        return ApiResponse::success(null, "Berhasil menghapus {$vouchers->count()} voucher", );
    }


    public function bulkUpdateStatus(Request $request, Merchant $merchant)
    {
        $this->authorize('manageMerchant', [Voucher::class, $merchant]);

        $data = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1'],
            'voucher_ids.*' => ['required', 'integer'],
            'voucher_status' => ['required', 'in:active,inactive'],
        ]);

        $updated = Voucher::whereIn('id', $data['voucher_ids'])
            ->where('merchant_id', $merchant->id)
            ->update([
                'voucher_status' => $data['voucher_status'],
            ]);

        return ApiResponse::success(null, "Berhasil mengubah status {$updated} voucher", );
    }

    public function customerVouchersByMerchant(Request $request, Merchant $merchant)
    {
        $userId = $request->user()->id;

        $acceptedEventIds = DB::table('event_merchants')
            ->select('event_id')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'accepted');

        $vouchers = Voucher::query()
            ->where(function ($q) use ($merchant, $acceptedEventIds) {
                $q->where('merchant_id', $merchant->id)
                    ->orWhereIn('event_id', $acceptedEventIds);
            })
            ->active()

            // ⬅️ hitung total pemakaian
            ->withCount('usages')

            // ⬅️ hitung pemakaian user ini
            ->withCount([
                'usages as user_usages_count' => function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                }
            ])

            ->get([
                'id',
                'event_id',
                'voucher_name',
                'voucher_code',
                'voucher_type',
                'voucher_description',
                'voucher_end_date',
                'value',
                'max_discount_amount',
                'min_purchase_amount',
                'usage_limit_per_user',
                'usage_limit',
                'voucher_end_date',
            ]);

        // =============================
        // FILTER + FORMAT (MANUAL)
        // =============================
        $vouchers = $vouchers->filter(function ($voucher) {
            $totalUsed = $voucher->usages_count ?? 0;
            $userUsed = $voucher->user_usages_count ?? 0;

            // total limit
            if (
                $voucher->usage_limit !== null &&
                $totalUsed >= $voucher->usage_limit
            ) {
                return false;
            }

            // per user limit
            if (
                $voucher->usage_limit_per_user !== null &&
                $userUsed >= $voucher->usage_limit_per_user
            ) {
                return false;
            }

            return true;
        })->values();

        // =============================
        // TRANSFORM RESPONSE
        // =============================
        $vouchers->each(function ($voucher) {
            $voucher->usage = ($voucher->usages_count ?? 0) . ' / ' . $voucher->usage_limit;
            $voucher->is_expired = $voucher->voucher_end_date
                ? Carbon::parse($voucher->voucher_end_date)->isPast()
                : false;

            $voucher->makeHidden([
                'created_at',
                'updated_at',
                'usages_count',
                'user_usages_count',
                'voucher_end_date',
            ]);
        });

        return ApiResponse::success($vouchers, 'Daftar voucher tersedia');
    }




}
