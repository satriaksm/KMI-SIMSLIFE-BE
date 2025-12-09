<?php
namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     * PUBLIC: Show single event
     */
    public function show($id)
    {
        $event = Event::with([
            'creator:id,name',
            'merchants' => function ($query) {
                $query->where('event_merchants.status', 'accepted');
            },
            'vouchers' => function ($query) {
                $query->active();
            }
        ])->findOrFail($id);

        return response()->json($event);
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