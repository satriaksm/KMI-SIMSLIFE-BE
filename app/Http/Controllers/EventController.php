<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use enshrined\svgSanitize\Sanitizer;

class EventController extends Controller
{
    public function indexByMerchant(Request $request)
    {
        $merchantId = $request->integer('merchant_id');
        if (!$merchantId) {
            return response()->json([
                'message' => 'merchant_id is required',
            ], 422);
        }

        // Ensure the merchant belongs to the authenticated user
        $ownsMerchant = $request->user()
                ?->merchants()
            ->whereKey($merchantId)
            ->exists();

        if (!$ownsMerchant) {
            return response()->json([
                'message' => 'Unauthorized merchant',
            ], 403);
        }

        $invitationStatus = $request->input('invitation_status');
        $q = trim((string) $request->input('q', ''));

        $query = Event::whereHas('merchants', function ($builder) use ($merchantId, $invitationStatus) {
            $builder->where('merchants.id', $merchantId);
            if ($invitationStatus) {
                $builder->where('event_merchants.status', $invitationStatus);
            }
        })
            ->with(['creator:id,name'])
            ->with([
                'vouchers' => function ($builder) {
                    $builder->select([
                        'id',
                        'event_id',
                        'voucher_name',
                        'voucher_code',
                        'voucher_status',
                        'voucher_type',
                        'value',
                        'voucher_start_date',
                        'voucher_end_date',
                    ])->orderByDesc('id');
                }
            ])
            // Load only the pivot for this merchant (so we can expose invitation_status)
            ->with([
                'merchants' => function ($builder) use ($merchantId) {
                    $builder->where('merchants.id', $merchantId)->select('merchants.id');
                }
            ]);

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('event_name', 'like', "%{$q}%")
                    ->orWhere('event_description', 'like', "%{$q}%");
            });
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $events = $query->latest('event_start_date')
            ->paginate($request->input('per_page', 10));

        // Flatten invitation status for this merchant
        $events->getCollection()->transform(function ($event) {
            $merchant = $event->merchants->first();
            $event->invitation_status = $merchant?->pivot?->status;
            $event->responded_at = $merchant?->pivot?->responded_at;

            // Only show vouchers when the invitation is accepted
            if (($event->invitation_status ?? null) !== 'accepted') {
                $event->vouchers = [];
            }

            unset($event->merchants);
            return $event;
        });

        return response()->json($events);
    }

    public function show(Request $request, $id)
    {
        $merchantId = $request->integer('merchant_id');
        if (!$merchantId) {
            return response()->json([
                'message' => 'merchant_id is required',
            ], 422);
        }

        $ownsMerchant = $request->user()
                ?->merchants()
            ->whereKey($merchantId)
            ->exists();

        if (!$ownsMerchant) {
            return response()->json([
                'message' => 'Unauthorized merchant',
            ], 403);
        }

        $event = Event::whereHas('merchants', function ($q) use ($merchantId) {
            $q->where('merchants.id', $merchantId);
        })
            ->with(['creator:id,name'])
            ->with([
                'vouchers' => function ($builder) {
                    $builder->select([
                        'id',
                        'event_id',
                        'voucher_name',
                        'voucher_code',
                        'voucher_status',
                        'voucher_type',
                        'value',
                        'voucher_start_date',
                        'voucher_end_date',
                    ])->orderByDesc('id');
                }
            ])
            ->with([
                'merchants' => function ($builder) use ($merchantId) {
                    $builder->where('merchants.id', $merchantId)->select('merchants.id');
                }
            ])
            ->findOrFail($id);

        $merchant = $event->merchants->first();
        $event->invitation_status = $merchant?->pivot?->status;
        $event->responded_at = $merchant?->pivot?->responded_at;

        if (($event->invitation_status ?? null) !== 'accepted') {
            $event->vouchers = [];
        }

        unset($event->merchants);

        return response()->json([
            'data' => $event,
        ]);
    }

    public function approvalByMerchant(Request $request, $id)
    {
        $validated = $request->validate([
            'merchant_id' => 'required|integer',
            'status' => 'required|in:accepted,rejected',
        ]);

        $merchantId = (int) $validated['merchant_id'];

        $ownsMerchant = $request->user()
                ?->merchants()
            ->whereKey($merchantId)
            ->exists();

        if (!$ownsMerchant) {
            return response()->json([
                'message' => 'Unauthorized merchant',
            ], 403);
        }

        $eventId = (int) $id;

        $event = Event::whereHas('merchants', function ($q) use ($merchantId) {
            $q->where('merchants.id', $merchantId);
        })->findOrFail($eventId);

        $event->merchants()->updateExistingPivot($merchantId, [
            'status' => $validated['status'],
            'responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Event invitation ' . $validated['status'] . ' successfully',
        ]);
    }

    /**
     * PUBLIC: Get active events
     */
    public function index(Request $request)
    {
        $query = Event::with(['creator:id,name'])
            ->published();

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $events = $query->latest('event_start_date')
            ->paginate($request->input('per_page', 10));

        return response()->json($events);
    }

    /**
     * ADMIN: Update event
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'event_name' => 'sometimes|required|string|max:255|unique:events,event_name,' . $id,
            'event_start_date' => 'sometimes|required|date',
            'event_end_date' => 'sometimes|required|date|after_or_equal:event_start_date',
            'banner_img' => 'nullable|mimes:jpeg,jpg,png,webp,svg|max:5120',
            'status' => 'sometimes|required|in:draft,published,archived',
        ], [
            'event_name.unique' => 'Nama event sudah digunakan. Gunakan nama yang berbeda.',
            'banner_img.mimes' => 'Format banner harus JPG, PNG, WebP, atau SVG',
            'banner_img.max' => 'Ukuran banner maksimal 5MB',
        ]);

        if ($request->hasFile('banner_img')) {
            $file = $request->file('banner_img');

            // Sanitize SVG files
            if ($file->getClientOriginalExtension() === 'svg') {
                $sanitizer = new Sanitizer();
                $dirtySVG = file_get_contents($file->getRealPath());
                $cleanSVG = $sanitizer->sanitize($dirtySVG);

                if ($cleanSVG === false) {
                    return response()->json([
                        'message' => 'File SVG tidak valid atau berbahaya',
                    ], 422);
                }

                // Delete old banner
                if ($event->banner_img_path) {
                    Storage::disk('public')->delete($event->banner_img_path);
                }

                // Save sanitized SVG
                $path = 'events/banners/' . uniqid() . '.svg';
                Storage::disk('public')->put($path, $cleanSVG);
                $validated['banner_img_path'] = $path;
            } else {
                // Delete old banner
                if ($event->banner_img_path) {
                    Storage::disk('public')->delete($event->banner_img_path);
                }

                $validated['banner_img_path'] = $file->store('events/banners', 'public');
            }
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
            'event_name' => 'required|string|max:255|unique:events,event_name', // ✅ ADDED unique
            'event_description' => 'nullable|string',
            'event_start_date' => 'required|date',
            'event_end_date' => 'required|date|after_or_equal:event_start_date',
            'banner_img' => 'required|mimes:jpeg,jpg,png,webp,svg|max:5120', // ✅ REQUIRED
            'status' => 'required|in:draft,published,archived',
        ], [
            'event_name.unique' => 'Nama event sudah digunakan. Gunakan nama yang berbeda.', // ✅ ADDED
            'banner_img.required' => 'Banner event wajib diupload',
            'banner_img.mimes' => 'Format banner harus JPG, PNG, WebP, atau SVG',
            'banner_img.max' => 'Ukuran banner maksimal 5MB',
        ]);

        $validated['created_by'] = $request->user()->id;

        if ($request->hasFile('banner_img')) {
            $file = $request->file('banner_img');

            // Sanitize SVG files
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
                $validated['banner_img_path'] = $file->store('events/banners', 'public');
            }
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
     * Public: Get published events (for homepage banner)
     * Only return events that are currently active (published & within date range)
     */
    public function publicIndex()
    {
        $today = Carbon::today();

        $events = Event::where('status', 'published')
            ->whereDate('event_start_date', '<=', $today)
            ->whereDate('event_end_date', '>=', $today)
            ->select('id', 'event_name', 'event_description', 'banner_img_path', 'event_start_date', 'event_end_date')
            ->orderBy('event_start_date', 'desc')
            ->limit(10) // Limit to 10 latest events
            ->get();

        return response()->json([
            'data' => $events,
        ]);
    }
}
