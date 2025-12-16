<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
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
     * ADMIN: List all events (with filters)
     */
    public function adminIndex(Request $request)
    {
        Log::info('[EventController] adminIndex called', [
            'params' => $request->all()
        ]);

        $query = Event::with(['creator:id,name'])
            ->withCount(['merchants', 'vouchers']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('event_name', 'like', "%{$search}%");
        }

        // Filter active only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $events = $query->latest('event_start_date')
            ->paginate($request->input('per_page', 10));

        Log::info('[EventController] Returning events', [
            'count' => $events->count(),
            'total' => $events->total()
        ]);

        return response()->json($events);
    }

    /**
     * ADMIN: Get single event detail
     */
    public function adminShow($id)
    {
        $event = Event::with([
            'creator:id,name',
            'merchants' => function ($query) {
                $query->where('event_merchants.status', 'accepted');
            },
            'vouchers'
        ])
            ->withCount(['merchants', 'vouchers'])
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
}
