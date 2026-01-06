<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\Merchant;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminVoucherController extends Controller
{
    // List vouchers with filter & pagination
    public function index(Request $request)
    {
        try {
            $query = Voucher::with(['merchant', 'event'])
                ->when($request->voucher_status, fn($q) => $q->where('voucher_status', $request->voucher_status))
                ->when($request->voucher_type, fn($q) => $q->where('voucher_type', $request->voucher_type))
                ->when($request->merchant_id, fn($q) => $q->where('merchant_id', $request->merchant_id))
                ->when($request->search, function ($q) use ($request) {
                    $q->where(function ($sub) use ($request) {
                        $sub->where('voucher_code', 'like', '%' . $request->search . '%')
                            ->orWhere('voucher_description', 'like', '%' . $request->search . '%');
                    });
                });

            // Sorting
            if ($request->sort_by === 'name_asc') {
                $query->orderBy('voucher_code', 'asc');
            } elseif ($request->sort_by === 'name_desc') {
                $query->orderBy('voucher_code', 'desc');
            } elseif ($request->sort_by === 'start_newest') {
                $query->orderBy('voucher_start_date', 'desc');
            } elseif ($request->sort_by === 'start_oldest') {
                $query->orderBy('voucher_start_date', 'asc');
            } else {
                $query->orderByDesc('id');
            }

            $perPage = $request->input('per_page', 15);
            $vouchers = $query->paginate($perPage);

            // Tambahkan usages_count jika perlu
            $vouchers->getCollection()->transform(function ($voucher) {
                $voucher->usages_count = $voucher->usages()->count() ?? 0;
                return $voucher;
            });

            return response()->json([
                'data' => $vouchers->items(),
                'meta' => [
                    'current_page' => $vouchers->currentPage(),
                    'last_page' => $vouchers->lastPage(),
                    'per_page' => $vouchers->perPage(),
                    'total' => $vouchers->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memuat data voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Show voucher detail
    public function show($id)
    {
        try {
            $voucher = Voucher::with(['merchant', 'event', 'usages'])->findOrFail($id);
            $voucher->usages_count = $voucher->usages()->count() ?? 0;
            return response()->json(['data' => $voucher]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Voucher tidak ditemukan'], 404);
        }
    }

    // Create voucher (contoh sederhana)
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'voucher_code' => 'required|string|unique:vouchers,voucher_code',
                'voucher_description' => 'nullable|string',
                'voucher_type' => 'required|in:percent,fixed',
                'value' => 'required|numeric|min:1',
                'voucher_status' => 'required|in:active,inactive',
                'voucher_start_date' => 'required|date',
                'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
                'merchant_id' => 'nullable|exists:merchants,id',
                'event_id' => 'nullable|exists:events,id',
                'usage_limit' => 'nullable|integer|min:1',
                'usage_limit_per_user' => 'nullable|integer|min:1',
                'max_discount_amount' => 'nullable|numeric|min:0',
                'min_purchase_amount' => 'nullable|numeric|min:0',
            ]);

            $voucher = Voucher::create($data);

            return response()->json([
                'message' => 'Voucher berhasil dibuat',
                'data' => $voucher
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal membuat voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Delete voucher
    public function destroy($id)
    {
        try {
            $voucher = Voucher::findOrFail($id);
            if ($voucher->usages()->count() > 0) {
                return response()->json([
                    'message' => 'Voucher sudah digunakan, tidak dapat dihapus'
                ], 400);
            }
            $voucher->delete();
            return response()->json(['message' => 'Voucher berhasil dihapus']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Voucher tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menghapus voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}