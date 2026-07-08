<?php

namespace App\Observers;

use App\Models\Event;
use App\Models\CommunityPost;
use App\Events\CommunityPostCreated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EventObserver
{
    /**
     * Schedule an auto-post to community when a new event is created.
     * The post will be dispatched at the event's start date.
     */
    public function created(Event $event): void
    {
        try {
            // Use the event creator as the post author (fallback to first admin)
            $adminId = $event->created_by ?? \App\Models\User::where('is_super_admin', true)->value('id');
            if (!$adminId) {
                Log::warning('[EventObserver] No admin found to create community post', ['event_id' => $event->id]);
                return;
            }

            // Calculate delay to the event start date (midnight)
            $startDate  = Carbon::parse($event->event_start_date)->startOfDay();
            $now        = Carbon::now();
            $delaySeconds = max(0, $now->diffInSeconds($startDate, false));

            // Dispatch the community post creation job with delay
            \App\Jobs\PostEventToCommunity::dispatch($event->id, $adminId)
                ->delay(now()->addSeconds($delaySeconds));

            Log::info('[EventObserver] Scheduled community post', [
                'event_id'      => $event->id,
                'admin_id'      => $adminId,
                'post_at'       => $startDate->toISOString(),
                'delay_seconds' => $delaySeconds,
            ]);
        } catch (\Exception $e) {
            Log::error('[EventObserver] Failed to schedule community post', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function updated(Event $event): void
    {
        // No auto-action needed on update; admin can manually post via button
    }

    public function deleted(Event $event): void
    {
        // Optionally archive community posts linked to this event
        CommunityPost::where('event_id', $event->id)
            ->where('post_type', 'event')
            ->update(['post_status' => 'archived']);
    }
}
