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

    public function merchantStore(Request $request, Merchant $merchant)
    {
        $this->authorize('manageMerchant', [Voucher::class, $merchant]);

        $data = $request->validate([
            'voucher_name' => 'required|string|max:100',
            'voucher_code' => [
                'required',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($merchant) {
                    $exists = \App\Models\Voucher::where('voucher_code', $value)
                        ->where(function ($q) use ($merchant) {
                            $q->where('merchant_id', $merchant->id)
                              ->orWhereNull('merchant_id');
                        })
                        ->exists();
                    if ($exists) {
                        $fail('Kode voucher sudah digunakan.');
                    }
                }
            ],
            'voucher_description' => 'nullable|string',
            'voucher_type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'voucher_start_date' => 'required|date',
            'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_purchase_amount' => 'nullable|numeric|min:0',
            'usage_limit_per_user' => 'required|integer|min:1',
            'usage_limit' => 'nullable|integer|min:1',
            'is_secret' => 'nullable|boolean',
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
            'voucher_code' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($merchant, $voucher) {
                    $exists = \App\Models\Voucher::where('voucher_code', $value)
                        ->where('id', '!=', $voucher->id)
                        ->where(function ($q) use ($merchant) {
                            $q->where('merchant_id', $merchant->id)
                              ->orWhereNull('merchant_id');
                        })
                        ->exists();
                    if ($exists) {
                        $fail('Kode voucher sudah digunakan.');
                    }
                }
            ],
            'voucher_description' => 'required|string',
            'voucher_type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:1',
            'voucher_start_date' => 'required|date',
            'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
            'min_purchase_amount' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0|required_if:voucher_type,percent',
            'usage_limit_per_user' => 'required|integer|min:1',
            'usage_limit' => 'required|integer|min:0',
            'is_secret' => 'nullable|boolean',
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

        // If it's a merchant's own voucher
        if ((int) $voucher->merchant_id === (int) $merchant->id) {
            $voucher->update([
                'voucher_status' => $data['voucher_status'],
            ]);
        } else {
            // It's an event voucher, update the pivot status
            $voucher->merchantsVoucher()->updateExistingPivot($merchant->id, [
                'status' => $data['voucher_status'],
                'activated_at' => $data['voucher_status'] === 'active' ? now() : null,
            ]);
        }

        return ApiResponse::success($voucher->fresh(), 'Status voucher berhasil diperbarui.');
    }

    /**
     * MERCHANT: Get restricted products for an event voucher
     */
    public function getRestrictedProducts(Merchant $merchant, Voucher $voucher)
    {
        $this->authorize('view', [$voucher, $merchant]);

        $products = $voucher->restrictedProducts()
            ->wherePivot('merchant_id', $merchant->id)
            ->get(['products.id', 'products.name', 'products.slug']);

        return ApiResponse::success($products, 'Daftar produk terlarang berhasil dimuat.');
    }

    /**
     * MERCHANT: Set restricted products for an event voucher
     */
    public function setRestrictedProducts(Request $request, Merchant $merchant, Voucher $voucher)
    {
        $this->authorize('update', [$voucher, $merchant]);

        $data = $request->validate([
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        DB::transaction(function () use ($voucher, $merchant, $data) {
            // Remove existing for this merchant
            $voucher->restrictedProducts()->wherePivot('merchant_id', $merchant->id)->detach();

            // Attach new
            if (!empty($data['product_ids'])) {
                foreach ($data['product_ids'] as $productId) {
                    $voucher->restrictedProducts()->attach($productId, [
                        'merchant_id' => $merchant->id
                    ]);
                }
            }
        });

        return ApiResponse::success(null, 'Pembatasan produk berhasil diperbarui.');
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
            ->where('is_secret', false)
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

    /**
     * PUBLIC: Get available vouchers for a merchant by ID
     * GET /api/public/merchants/{merchantId}/vouchers
     * Used by customer during checkout (no auth required)
     */
    public function publicVouchersByMerchantId(Request $request, $merchantId)
    {
        $merchant = Merchant::find($merchantId);
        
        if (!$merchant) {
            return response()->json([
                'message' => 'Merchant tidak ditemukan'
            ], 404);
        }

        // Get accepted event IDs for this merchant
        $acceptedEventIds = DB::table('event_merchants')
            ->select('event_id')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'accepted')
            ->pluck('event_id');

        $query = Voucher::query()
            ->where(function ($q) use ($merchant, $acceptedEventIds) {
                $q->where('merchant_id', $merchant->id)
                    ->orWhereIn('event_id', $acceptedEventIds);
            })
            ->where('is_secret', false)
            ->active()
            ->withCount('usages');

        // Filter by minimum purchase amount if provided
        if ($request->has('amount')) {
            $amount = (float) $request->amount;
            $query->where(function ($q) use ($amount) {
                $q->whereNull('min_purchase_amount')
                    ->orWhere('min_purchase_amount', '<=', $amount);
            });
        }

        // If user is authenticated, count their usage
        $userId = $request->user()?->id;
        if ($userId) {
            $query->withCount([
                'usages as user_usages_count' => function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                }
            ]);
        }

        $vouchers = $query->get([
            'id',
            'merchant_id',
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
        ]);

        // Filter out vouchers that reached their limit
        $vouchers = $vouchers->filter(function ($voucher) use ($userId) {
            $totalUsed = $voucher->usages_count ?? 0;

            // Check total usage limit
            if ($voucher->usage_limit !== null && $totalUsed >= $voucher->usage_limit) {
                return false;
            }

            // Check per-user limit if user is authenticated
            if ($userId && $voucher->usage_limit_per_user !== null) {
                $userUsed = $voucher->user_usages_count ?? 0;
                if ($userUsed >= $voucher->usage_limit_per_user) {
                    return false;
                }
            }

            return true;
        })->values();

        // Transform response
        $vouchers->each(function ($voucher) {
            $voucher->usage = ($voucher->usages_count ?? 0) . ' / ' . ($voucher->usage_limit ?? '∞');
            $voucher->is_expired = $voucher->voucher_end_date
                ? Carbon::parse($voucher->voucher_end_date)->isPast()
                : false;

            $voucher->makeHidden([
                'usages_count',
                'user_usages_count',
            ]);
        });

        return ApiResponse::success($vouchers, 'Daftar voucher tersedia');
    }



    public function validateVoucher(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        $userId = $request->user()->id;

        $acceptedEventIds = DB::table('event_merchants')
            ->select('event_id')
            ->where('merchant_id', $merchant->id)
            ->where('status', 'accepted')
            ->pluck('event_id');

        $voucher = Voucher::query()
            ->where('voucher_code', $data['code'])
            ->where(function ($q) use ($merchant, $acceptedEventIds) {
                $q->where('merchant_id', $merchant->id)
                    ->orWhereIn('event_id', $acceptedEventIds);
            })
            ->active()
            ->withCount('usages')
            ->withCount([
                'usages as user_usages_count' => function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                }
            ])
            ->first([
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
                'is_secret',
            ]);

        if (!$voucher) {
            return ApiResponse::error('Voucher tidak valid atau sudah kadaluarsa', 400);
        }

        $totalUsed = $voucher->usages_count ?? 0;
        $userUsed = $voucher->user_usages_count ?? 0;

        if ($voucher->usage_limit !== null && $totalUsed >= $voucher->usage_limit) {
            return ApiResponse::error('Kuota voucher sudah habis', 400);
        }

        if ($voucher->usage_limit_per_user !== null && $userUsed >= $voucher->usage_limit_per_user) {
            return ApiResponse::error('Anda sudah mencapai batas penggunaan voucher ini', 400);
        }

        $voucher->usage = $totalUsed . ' / ' . ($voucher->usage_limit ?? '∞');
        $voucher->is_expired = false; // Karena query filter by active()
        
        $voucher->makeHidden([
            'usages_count',
            'user_usages_count',
        ]);

        return ApiResponse::success($voucher, 'Voucher berhasil digunakan');
    }
}
