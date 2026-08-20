<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Merchant;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use enshrined\svgSanitize\Sanitizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class AdminEventController extends Controller
{
    /**
     * Stream event banner image
     */
    public function showBanner(Request $request, Event $event)
    {
        // Support signed URL for secure access
        if ($request->hasValidSignature()) {
            return $this->streamEventBanner($event);
        }

        // Public access for now (you can add auth checks later)
        return $this->streamEventBanner($event);
    }

    /**
     * Private method to stream banner image
     */
    private function streamEventBanner(Event $event)
    {
        if (empty($event->banner_img_path)) {
            abort(404);
        }

        $disk = 'public';
        $path = ltrim($event->banner_img_path, '/');

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        $stream = Storage::disk($disk)->readStream($path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    /**
     * ADMIN: List all events (with filters)
     */
    public function index(Request $request)
    {
        // Auto-archive expired events setiap kali index dipanggil
        $this->autoArchiveEvents();

        $query = Event::with(['creator:id,name'])
            ->withCount(['merchants', 'vouchers']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('event_name', 'like', "%{$search}%");
        }

        // Filter active only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $events = $query->latest('event_start_date')
            ->paginate($request->input('per_page', 10));

        return response()->json($events);
    }

    /**
     * ADMIN: Get single event detail
     */
    public function show($id)
    {
        // Auto-archive saat show juga
        $this->autoArchiveEvents();

        $event = Event::with([
            'creator:id,name',
            'merchants' => function ($query) {
                $query->withPivot([
                    'status',
                    'removal_reason',
                    'removed_by',
                    'removed_at',
                    'responded_at'
                ]);
            },
            'merchants.segmentation',
            'merchants.paguyuban',
            'vouchers'
        ])
            ->withCount([
                'merchants as active_merchants_count' => function ($query) {
                    $query->where('event_merchants.status', 'accepted');
                },
                'merchants as removed_merchants_count' => function ($query) {
                    $query->where('event_merchants.status', 'removed');
                },
                'vouchers'
            ])
            ->findOrFail($id);

        return response()->json(['data' => $event]);
    }

    /**
     * Auto-archive events yang sudah lewat end_date
     * Dan auto-draft events yang belum dimulai tapi statusnya published
     */
    private function autoArchiveEvents()
    {
        $today = Carbon::today();

        // 1. Archive events yang sudah lewat end_date
        $archivedCount = Event::where('status', 'published')
            ->whereDate('event_end_date', '<', $today)
            ->update(['status' => 'archived']);

        // 2. Auto-draft events yang belum dimulai (status published tapi sebelum start_date)
        $draftedCount = Event::where('status', 'published')
            ->whereDate('event_start_date', '>', $today)
            ->update(['status' => 'draft']);

        if ($archivedCount > 0 || $draftedCount > 0) {
            Log::info('[AdminEvent] Auto-updated event statuses', [
                'archived_count' => $archivedCount,
                'drafted_count' => $draftedCount,
                'date' => $today->format('Y-m-d'),
            ]);
        }

        return [
            'archived' => $archivedCount,
            'drafted' => $draftedCount,
        ];
    }

    /**
     * ADMIN: Manual trigger auto-archive (optional endpoint)
     */
    public function triggerAutoArchive()
    {
        $result = $this->autoArchiveEvents();

        return response()->json([
            'message' => 'Auto-archive completed',
            'archived_count' => $result['archived'],
            'drafted_count' => $result['drafted'],
        ]);
    }

    /**
     * ADMIN: Update event
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'event_name' => 'sometimes|required|string|max:255|unique:events,event_name,' . $id, 
            'event_description' => 'nullable|string',
            'event_start_date' => 'sometimes|required|date',
            'event_end_date' => 'sometimes|required|date|after_or_equal:event_start_date',
            'banner_img' => 'nullable|mimes:jpeg,jpg,png,webp,svg|max:5120',
            'status' => 'sometimes|required|in:draft,published,archived',
            'merchant_ids' => 'nullable|array',
            'merchant_ids.*' => 'exists:merchants,id',
            'voucher_ids' => 'nullable|array',
            'voucher_ids.*' => 'exists:vouchers,id',
        ], [
            'event_name.unique' => 'Nama event sudah digunakan. Gunakan nama yang berbeda.', 
            'banner_img.mimes' => 'Format banner harus JPG, PNG, WebP, atau SVG',
            'banner_img.max' => 'Ukuran banner maksimal 5MB',
        ]);

        $startDate = isset($validated['event_start_date']) 
            ? Carbon::parse($validated['event_start_date'])
            : Carbon::parse($event->event_start_date);
        
        $endDate = isset($validated['event_end_date'])
            ? Carbon::parse($validated['event_end_date'])
            : Carbon::parse($event->event_end_date);

        $today = Carbon::today();
        $originalStartDate = Carbon::parse($event->event_start_date);

        if (isset($validated['event_start_date']) && !$startDate->eq($originalStartDate)) {
            if ($originalStartDate->lt($today)) {
                return response()->json([
                    'message' => 'Event yang sudah dimulai tidak dapat diubah tanggal mulainya',
                    'errors' => [
                        'event_start_date' => ['Tanggal mulai event yang sudah berjalan tidak dapat diubah untuk menjaga integritas data']
                    ]
                ], 422);
            }

            if ($startDate->lt($today)) {
                return response()->json([
                    'message' => 'Tanggal mulai tidak boleh di masa lalu',
                    'errors' => [
                        'event_start_date' => ['Tanggal mulai harus hari ini atau di masa depan']
                    ]
                ], 422);
            }
        }

        // Handle banner upload BEFORE status validation
        if ($request->hasFile('banner_img')) {
            $file = $request->file('banner_img');
            $imageService = app(\App\Services\ImageOptimizationService::class);
            
            // Delete old banner FIRST
            if ($event->banner_img_path) {
                $imageService->deleteImages($event->banner_img_path, 'public');
                Log::info('[AdminEvent] Old banner deleted', [
                    'event_id' => $event->id,
                    'old_path' => $event->banner_img_path,
                ]);
            }
            
            // Upload new banner
            if ($file->getClientOriginalExtension() === 'svg') {
                $sanitizer = new Sanitizer();
                $dirtySVG = file_get_contents($file->getRealPath());
                $cleanSVG = $sanitizer->sanitize($dirtySVG);
                
                if ($cleanSVG === false) {
                    return response()->json([
                        'message' => 'File SVG tidak valid atau berbahaya',
                    ], 422);
                }
                
                $path = 'events/banners/' . uniqid() . '.svg';
                Storage::disk('public')->put($path, $cleanSVG);
                $validated['banner_img_path'] = $path;
            } else {
                $validated['banner_img_path'] = $imageService->processAndStore(
                    $file,
                    'events/banners',
                    'public',
                    false
                );
            }
            
            Log::info('[AdminEvent] New banner uploaded', [
                'event_id' => $event->id,
                'new_path' => $validated['banner_img_path'],
            ]);
        }

        if (isset($validated['status'])) {
            // Event already started
            if ($originalStartDate->lt($today)) {
                if ($validated['status'] === 'draft' && $event->status === 'published') {
                    return response()->json([
                        'message' => 'Event yang sudah berjalan tidak dapat diubah ke status Draft',
                        'suggestion' => 'Gunakan status Published atau Archived',
                    ], 422);
                }
            }

            if ($validated['status'] === 'published') {
                if ($today->lt($startDate)) {
                    return response()->json([
                        'message' => 'Event belum dapat dipublish karena belum memasuki tanggal mulai',
                        'current_date' => $today->format('Y-m-d'),
                        'start_date' => $startDate->format('Y-m-d'),
                    ], 422);
                }

                if ($today->gt($endDate)) {
                    return response()->json([
                        'message' => 'Event tidak dapat dipublish karena sudah melewati tanggal selesai',
                        'current_date' => $today->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d'),
                    ], 422);
                }
            }

            // Auto-set to draft if before start date
            if ($today->lt($startDate) && $validated['status'] === 'published') {
                $validated['status'] = 'draft';
            }

            // Auto-archive if past end date
            if ($today->gt($endDate)) {
                $validated['status'] = 'archived';
            }
        }

        $event->update($validated);

        // Bulk invite merchants if provided
        if ($request->has('merchant_ids')) {
            $event->merchants()->syncWithoutDetaching(
                collect($request->merchant_ids)->mapWithKeys(fn($id) => [
                    $id => ['status' => 'pending']
                ])
            );
        }

        // Bulk attach vouchers if provided
        if ($request->has('voucher_ids')) {
            Voucher::whereIn('id', $request->voucher_ids)->update(['event_id' => $event->id]);
        }

        // ✅ FIX: Reload event dengan relasi untuk response yang konsisten
        $event = Event::with(['creator:id,name', 'merchants', 'vouchers'])
            ->withCount(['merchants', 'vouchers'])
            ->find($event->id);

        return response()->json([
            'message' => 'Event updated successfully',
            'data' => $event,
        ]);
    }

    /**
     * ADMIN: Delete event
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        // Delete banner image
        if ($event->banner_img_path) {
            app(\App\Services\ImageOptimizationService::class)->deleteImages($event->banner_img_path, 'public');
        }

        // Detach merchants
        $event->merchants()->detach();

        // Delete event
        $event->delete();

        return response()->json([
            'message' => 'Event deleted successfully'
        ]);
    }

    /**
     * ADMIN: Create event
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_name' => 'required|string|max:255|unique:events,event_name', // ✅ ADDED unique
            'event_description' => 'nullable|string',
            'event_start_date' => 'required|date|after_or_equal:today', 
            'event_end_date' => 'required|date|after_or_equal:event_start_date',
            'banner_img' => 'required|mimes:jpeg,jpg,png,webp,svg|max:5120', // ✅ REQUIRED
            'status' => 'required|in:draft,published,archived',
            'merchant_ids' => 'nullable|array',
            'merchant_ids.*' => 'exists:merchants,id',
            'voucher_ids' => 'nullable|array',
            'voucher_ids.*' => 'exists:vouchers,id',
        ], [
            'event_name.unique' => 'Nama event sudah digunakan. Gunakan nama yang berbeda.', // ✅ ADDED
            'event_start_date.after_or_equal' => 'Tanggal mulai tidak boleh di masa lalu',
            'banner_img.required' => 'Banner event wajib diupload', // ✅ ERROR MESSAGE
            'banner_img.mimes' => 'Format banner harus JPG, PNG, WebP, atau SVG',
            'banner_img.max' => 'Ukuran banner maksimal 5MB',
        ]);

        $startDate = Carbon::parse($validated['event_start_date']);
        $endDate = Carbon::parse($validated['event_end_date']);
        $today = Carbon::today();
        if ($startDate->eq($today) && $endDate->gte($today)) {
            if (!in_array($validated['status'], ['draft', 'published'])) {
                $validated['status'] = 'draft';
            }
        } elseif ($startDate->gt($today)) {
            $validated['status'] = 'draft';
        }

        $validated['created_by'] = $request->user()->id;

        if ($request->hasFile('banner_img')) {
            $file = $request->file('banner_img');
            
            if ($file->getClientOriginalExtension() === 'svg') {
                $sanitizer = new Sanitizer();
                $dirtySVG = file_get_contents($file->getRealPath());
                $cleanSVG = $sanitizer->sanitize($dirtySVG);
                
                if ($cleanSVG === false) {
                    return response()->json([
                        'message' => 'File SVG tidak valid atau berbahaya',
                    ], 422);
                }
                
                // Save sanitized SVG
                $path = 'events/banners/' . uniqid() . '.svg';
                Storage::disk('public')->put($path, $cleanSVG);
                $validated['banner_img_path'] = $path;
            } else {
                $imageService = app(\App\Services\ImageOptimizationService::class);
                $validated['banner_img_path'] = $imageService->processAndStore(
                    $file,
                    'events/banners',
                    'public',
                    false
                );
            }
        } else {
            // ✅ Double check (should never happen with validation)
            return response()->json([
                'message' => 'Banner event wajib diupload',
                'errors' => [
                    'banner_img' => ['Banner event wajib diupload']
                ]
            ], 422);
        }
        $event = Event::create($validated);

        // Bulk invite merchants if provided
        if (!empty($request->merchant_ids)) {
            $event->merchants()->syncWithoutDetaching(
                collect($request->merchant_ids)->mapWithKeys(fn($id) => [
                    $id => ['status' => 'pending']
                ])
            );
        }

        // Bulk attach vouchers if provided
        if (!empty($request->voucher_ids)) {
            Voucher::whereIn('id', $request->voucher_ids)->update(['event_id' => $event->id]);
        }

        return response()->json([
            'message' => 'Event created successfully',
            'data' => $event->load(['creator:id,name', 'merchants', 'vouchers']),
        ], 201);
    }

    /**
     * ADMIN: Invite merchants to event
     */
    public function inviteMerchants(Request $request, Event $event)
    {
        $validated = $request->validate([
            'merchant_ids' => 'required|array',
            'merchant_ids.*' => 'exists:merchants,id',
        ]);

        $event->merchants()->syncWithoutDetaching(
            collect($validated['merchant_ids'])->mapWithKeys(fn($id) => [
                $id => ['status' => 'pending']
            ])
        );

        return response()->json([
            'message' => 'Merchants invited successfully',
        ]);
    }

    /**
     * ADMIN: Attach multiple vouchers to event and all participating merchants
     */
    public function attachVoucher(Request $request, Event $event)
    {
        $validated = $request->validate([
            'voucher_ids' => 'required|array|min:1',
            'voucher_ids.*' => 'required|exists:vouchers,id',
        ]);

        try {
            $attachedCount = 0;
            $errors = [];

            DB::transaction(function () use ($event, $validated, &$attachedCount, &$errors) {
                foreach ($validated['voucher_ids'] as $voucherId) {
                    try {
                        $voucher = Voucher::findOrFail($voucherId);

                        // Check if voucher already attached to this event
                        if ($voucher->event_id === $event->id) {
                            $errors[] = "Voucher {$voucher->voucher_code} sudah terhubung dengan event ini";
                            continue;
                        }

                        // Check if voucher attached to other event
                        if ($voucher->event_id !== null) {
                            $errors[] = "Voucher {$voucher->voucher_code} sudah terhubung dengan event lain";
                            continue;
                        }

                        // Update voucher to link with event
                        $voucher->update([
                            'event_id' => $event->id,
                        ]);

                        // Get all accepted merchants in this event
                        $acceptedMerchants = $event->merchants()
                            ->wherePivot('status', 'accepted')
                            ->pluck('merchants.id');

                        // Attach voucher to all participating merchants
                        foreach ($acceptedMerchants as $merchantId) {
                            $voucher->merchantsVoucher()->syncWithoutDetaching([
                                $merchantId => [
                                    'status' => 'inactive',
                                    'voucher_type' => null,
                                    'discount_value' => null,
                                    'activated_at' => null,
                                ]
                            ]);
                        }

                        $attachedCount++;
                    } catch (\Exception $e) {
                        $errors[] = "Gagal menambahkan voucher ID {$voucherId}: {$e->getMessage()}";
                    }
                }
            });

            $message = $attachedCount > 0 
                ? "{$attachedCount} voucher berhasil ditambahkan ke event"
                : "Tidak ada voucher yang ditambahkan";

            return response()->json([
                'message' => $message,
                'attached_count' => $attachedCount,
                'errors' => $errors,
            ], $attachedCount > 0 ? 200 : 400);

        } catch (\Exception $e) {
            Log::error('[AdminEvent] Attach voucher failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal menambahkan voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN: Detach voucher from event
     */
    public function detachVoucher(Request $request, Event $event, Voucher $voucher)
    {
        try {
            DB::transaction(function () use ($voucher) {
                // Remove event_id from voucher
                $voucher->update([
                    'event_id' => null,
                ]);

                // Detach from all merchants
                $voucher->merchantsVoucher()->detach();
            });

            return response()->json([
                'message' => 'Voucher berhasil dilepas dari event',
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Detach voucher failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal melepas voucher dari event',
            ], 400);
        }
    }

    /**
     * ADMIN: Get available vouchers (not attached to any event)
     * Hanya voucher yang belum terhubung dengan event manapun
     */
    public function availableVouchers(Request $request)
    {
        try {
            $vouchers = Voucher::whereNull('event_id')
                ->where('voucher_status', 'active') 
                ->with(['usages'])
                ->withCount([
                    'usages' => function ($q) {
                        $q->completed();
                    }
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            $vouchers->transform(function ($voucher) {
                $voucher->is_available = true;
                $voucher->can_be_attached = true;
                return $voucher;
            });

            Log::info('[AdminEvent] Available vouchers fetched', [
                'count' => $vouchers->count(),
                'vouchers' => $vouchers->pluck('voucher_code')->toArray(),
            ]);

            return response()->json([
                'data' => $vouchers,
                'count' => $vouchers->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Get available vouchers failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal memuat daftar voucher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN: Remove merchant from event
     */
    public function removeMerchant(Request $request, Event $event, Merchant $merchant)
    {
        $validated = $request->validate([
            'removal_reason' => 'required|string|max:500',
        ]);

        try {
            DB::transaction(function () use ($event, $merchant, $validated, $request) {
                // Update pivot status to 'removed'
                $event->merchants()->updateExistingPivot($merchant->id, [
                    'status' => 'removed',
                    'removal_reason' => $validated['removal_reason'],
                    'removed_by' => $request->user()->id,
                    'removed_at' => now(),
                ]);

                // Optional: Detach vouchers from this merchant
                // (jika voucher event tidak boleh digunakan lagi)
                $eventVouchers = $event->vouchers;
                foreach ($eventVouchers as $voucher) {
                    $voucher->merchantsVoucher()->detach($merchant->id);
                }

                Log::info('[AdminEvent] Merchant removed from event', [
                    'event_id' => $event->id,
                    'merchant_id' => $merchant->id,
                    'reason' => $validated['removal_reason'],
                    'removed_by' => $request->user()->id,
                ]);
            });

            return response()->json([
                'message' => 'Merchant berhasil dikeluarkan dari event',
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Remove merchant failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal mengeluarkan merchant dari event',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN: Restore removed merchant
     */
    public function restoreMerchant(Request $request, Event $event, Merchant $merchant)
    {
        try {
            $event->merchants()->updateExistingPivot($merchant->id, [
                'status' => 'accepted',
                'removal_reason' => null,
                'removed_by' => null,
                'removed_at' => null,
            ]);

            Log::info('[AdminEvent] Merchant restored to event', [
                'event_id' => $event->id,
                'merchant_id' => $merchant->id,
                'restored_by' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Merchant berhasil dikembalikan ke event',
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Restore merchant failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal mengembalikan merchant',
            ], 500);
        }
    }

    /**
     * ADMIN: Get removed merchants history
     */
    public function removedMerchants(Event $event)
    {
        try {
            $removedMerchants = $event->merchants()
                ->wherePivot('status', 'removed')
                ->withPivot([
                    'status',
                    'removal_reason',
                    'removed_by',
                    'removed_at',
                    'responded_at'
                ])
                ->with([
                    'segmentation',
                    'paguyuban',
                ])
                ->get()
                ->map(function ($merchant) {
                    return [
                        'id' => $merchant->id,
                        'name' => $merchant->name,
                        'slug' => $merchant->slug,
                        'logo_url' => $merchant->logo_url,
                        'segmentation' => $merchant->segmentation,
                        'removal_info' => [
                            'reason' => $merchant->pivot->removal_reason,
                            'removed_by' => $merchant->pivot->removed_by,
                            'removed_at' => $merchant->pivot->removed_at,
                        ],
                    ];
                });

            return response()->json([
                'data' => $removedMerchants,
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Get removed merchants failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal memuat riwayat merchant yang dikeluarkan',
            ], 500);
        }
    }

    /**
     * Export Events to PDF (Admin)
     *
     * Export list of events to PDF with filters.
     *
     * @authenticated
     *
     * @queryParam status string Filter by status. Example: published
     * @queryParam search string Search query. Example: Festival
     *
     * @response 200 application/pdf
     */
    public function exportPdf(Request $request)
    {
        try {
            $admin = $request->user();

            // Build query with same filters as index
            $query = Event::with(['creator:id,name'])
                ->withCount(['merchants', 'vouchers']);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($search = $request->input('search')) {
                $query->where('event_name', 'like', "%{$search}%");
            }

            $events = $query->latest('event_start_date')->limit(500)->get();

            // Metadata
            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $admin->name ?? 'Admin',
                'generated_by_email' => $admin->email ?? '-',
                'total_events' => $events->count(),
                'filters' => [
                    'status' => $request->input('status') ?: 'Semua',
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
            $pdf = Pdf::loadView('exports.admin.admin-event', [
                'events' => $events,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'landscape')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'events-report-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Export PDF failed', [
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

    /**
     * Export single event detail to PDF
     */
    public function exportEventDetailPdf(Request $request, $id)
    {
        try {
            $admin = $request->user();
            
            $event = Event::with([
                'creator:id,name',
                'merchants' => function ($query) {
                    $query->wherePivot('status', 'accepted')
                        ->with(['segmentation', 'paguyuban']);
                },
                'vouchers'
            ])
            ->withCount([
                'merchants as active_merchants_count' => function ($query) {
                    $query->where('event_merchants.status', 'accepted');
                },
                'vouchers'
            ])
            ->findOrFail($id);

            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $admin->name ?? 'Admin',
                'generated_by_email' => $admin->email ?? '-',
                'title' => 'Laporan Detail Event: ' . $event->event_name,
            ];

            // Load logo as base64
            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';
            
            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            }

            // Generate PDF
            $pdf = Pdf::loadView('exports.admin.admin-event-detail', [
                'event' => $event,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
            ->setPaper('a4', 'portrait')
            ->setOption('margin-top', 10)
            ->setOption('margin-right', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10);

            $filename = 'event-detail-' . $event->id . '-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminEvent] Export Detail PDF failed', [
                'event_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan detail PDF',
            ], 500);
        }
    }
}
