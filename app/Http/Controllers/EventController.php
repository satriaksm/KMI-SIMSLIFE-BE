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
            'event_name' => 'sometimes|required|string|max:255',
            'event_description' => 'nullable|string',
            'event_start_date' => 'sometimes|required|date',
            'event_end_date' => 'sometimes|required|date|after_or_equal:event_start_date',
            'banner_img' => 'nullable|mimes:jpeg,jpg,png,webp,svg|max:2048',
            'status' => 'sometimes|required|in:draft,published,archived',
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
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
            'event_start_date' => 'required|date',
            'event_end_date' => 'required|date|after_or_equal:event_start_date',
            'banner_img' => 'nullable|mimes:jpeg,jpg,png,webp,svg|max:2048',
            'status' => 'required|in:draft,published,archived',
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
