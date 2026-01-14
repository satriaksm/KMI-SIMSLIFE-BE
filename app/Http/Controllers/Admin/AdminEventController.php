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
use Barryvdh\DomPDF\Facade\Pdf;

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
     * ADMIN: Update event
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'event_name' => 'sometimes|required|string|max:255',
            'event_description' => 'nullable|string',
            'event_start_date' => 'sometimes|required|date',
            'event_end_date' => 'sometimes|required|date|after_or_equal:event_start_date',
            'banner_img' => 'nullable|image|max:2048',
            'status' => 'sometimes|required|in:draft,published,archived',
        ]);

        if ($request->hasFile('banner_img')) {
            // Delete old banner
            if ($event->banner_img_path) {
                Storage::disk('public')->delete($event->banner_img_path);
            }

            $validated['banner_img_path'] = $request->file('banner_img')
                ->store('events/banners', 'public');
        }

        $event->update($validated);

        return response()->json([
            'message' => 'Event updated successfully',
            'data' => $event->fresh(),
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
            Storage::disk('public')->delete($event->banner_img_path);
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
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
            'event_start_date' => 'required|date',
            'event_end_date' => 'required|date|after_or_equal:event_start_date',
            'banner_img' => 'nullable|image|max:2048',
            'status' => 'required|in:draft,published,archived',
        ]);

        $validated['created_by'] = $request->user()->id;

        if ($request->hasFile('banner_img')) {
            $validated['banner_img_path'] = $request->file('banner_img')
                ->store('events/banners', 'public');
        }

        $event = Event::create($validated);

        return response()->json([
            'message' => 'Event created successfully',
            'data' => $event,
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
                ->withCount('usages')
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
}
