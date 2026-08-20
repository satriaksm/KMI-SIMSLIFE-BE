<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\Merchant;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

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

            // Tambahkan usages_count jika perlu (hanya pesanan selesai)
            $vouchers->getCollection()->transform(function ($voucher) {
                $voucher->usages_count = $voucher->usages()->completed()->count() ?? 0;
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
            $voucher->usages_count = $voucher->usages()->completed()->count() ?? 0;
            return response()->json(['data' => $voucher]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Voucher tidak ditemukan'], 404);
        }
    }

    /**
     * Generate unique voucher code
     * Format: SUMILIR-{RANDOM_8_CHARS}
     */
    private function generateVoucherCode(): string
    {
        do {
            // Generate random 8 characters (uppercase + numbers)
            $randomChars = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8));
            $code = 'SUMILIR-' . $randomChars;
            
            // Check if code already exists
            $exists = Voucher::where('voucher_code', $code)->exists();
        } while ($exists);

        return $code;
    }

    // Create voucher (contoh sederhana)
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'voucher_name' => 'required|string|max:100|unique:vouchers,voucher_name', // ✅ ADD unique
                'voucher_description' => 'nullable|string',
                'voucher_type' => 'required|in:percent,fixed',
                'value' => [
                    'required',
                    'numeric',
                    'min:1',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($request->input('voucher_type') === 'percent' && $value > 100) {
                            $fail('Jika persentase, nilai tidak boleh lebih dari 100');
                        }
                    },
                ],
                'voucher_status' => 'required|in:active,inactive',
                'voucher_start_date' => 'required|date',
                'voucher_end_date' => 'required|date|after_or_equal:voucher_start_date',
                'merchant_id' => 'nullable|exists:merchants,id',
                'event_id' => 'nullable|exists:events,id',
                'usage_limit' => 'nullable|integer|min:1',
                'usage_limit_per_user' => 'nullable|integer|min:1',
                'max_discount_amount' => 'nullable|numeric|min:0',
                'min_purchase_amount' => 'nullable|numeric|min:0',
                'merchant_ids' => 'nullable|array',
                'merchant_ids.*' => 'exists:merchants,id',
                'is_hidden' => 'nullable|boolean',
            ], [
                'voucher_name.unique' => 'Nama voucher sudah digunakan. Gunakan nama yang berbeda.', // ✅ ADD error message
            ]);

            $data['is_hidden'] = $request->boolean('is_hidden');

            // ✅ Generate unique voucher code dengan validasi
            $attempts = 0;
            $maxAttempts = 10;
            
            do {
                $data['voucher_code'] = $this->generateVoucherCode();
                $exists = Voucher::where('voucher_code', $data['voucher_code'])->exists();
                $attempts++;
                
                if ($attempts >= $maxAttempts) {
                    throw new \Exception('Gagal generate kode voucher unik setelah ' . $maxAttempts . ' percobaan');
                }
            } while ($exists);

            // ✅ Create voucher
            $voucher = Voucher::create($data);

            // ✅ Attach to merchants if event voucher
            if (!empty($data['event_id']) && !empty($data['merchant_ids'])) {
                foreach ($data['merchant_ids'] as $merchantId) {
                    $voucher->merchantsVoucher()->attach($merchantId, [
                        'status' => 'inactive',
                        'voucher_type' => null,
                        'discount_value' => null,
                        'activated_at' => null,
                    ]);
                }
            }

            return response()->json([
                'message' => 'Voucher berhasil dibuat',
                'data' => $voucher->load(['merchant', 'event'])
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('[AdminVoucher] Create voucher failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal membuat voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Activate voucher
    public function activate($id)
    {
        try {
            $voucher = Voucher::findOrFail($id);
            $voucher->update(['voucher_status' => 'active']);
            
            return response()->json([
                'message' => 'Voucher berhasil diaktifkan',
                'data' => $voucher
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Voucher tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengaktifkan voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Deactivate voucher
    public function deactivate($id)
    {
        try {
            $voucher = Voucher::findOrFail($id);
            $voucher->update(['voucher_status' => 'inactive']);
            
            return response()->json([
                'message' => 'Voucher berhasil dinonaktifkan',
                'data' => $voucher
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Voucher tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menonaktifkan voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export Vouchers to PDF (Admin)
     *
     * Export list of vouchers to PDF with filters.
     *
     * @authenticated
     *
     * @queryParam voucher_status string Filter by status. Example: active
     * @queryParam voucher_type string Filter by type. Example: percent
     * @queryParam search string Search query. Example: PROMO
     *
     * @response 200 application/pdf
     */
    public function exportPdf(Request $request)
    {
        try {
            $admin = $request->user();

            // Build query with same filters as index
            $query = Voucher::with(['merchant', 'event'])
                ->when($request->voucher_status, fn($q) => $q->where('voucher_status', $request->voucher_status))
                ->when($request->voucher_type, fn($q) => $q->where('voucher_type', $request->voucher_type))
                ->when($request->search, function ($q) use ($request) {
                    $q->where(function ($sub) use ($request) {
                        $sub->where('voucher_code', 'like', '%' . $request->search . '%')
                            ->orWhere('voucher_description', 'like', '%' . $request->search . '%');
                    });
                });

            $vouchers = $query->latest('voucher_start_date')->limit(500)->get();

            // Add usages count (only completed orders)
            $vouchers->transform(function ($voucher) {
                $voucher->usages_count = $voucher->usages()->completed()->count() ?? 0;
                return $voucher;
            });

            // Metadata
            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $admin->name ?? 'Admin',
                'generated_by_email' => $admin->email ?? '-',
                'total_vouchers' => $vouchers->count(),
                'filters' => [
                    'status' => $request->input('voucher_status') ?: 'Semua',
                    'type' => $request->input('voucher_type') ?: 'Semua',
                    'search' => $request->input('search') ?: '-',
                ],
            ];

            // Load logo as base64
            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';
            
            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            }

            // Generate PDF
            $pdf = Pdf::loadView('exports.admin.admin-voucher', [
                'vouchers' => $vouchers,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'landscape')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'vouchers-report-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminVoucher] Export PDF failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan PDF',
            ], 500);
        }
    }
}