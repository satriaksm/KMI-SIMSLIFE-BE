<?php

namespace App\Jobs;

use App\Events\CommunityPostCreated;
use App\Models\CommunityPost;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PostEventToCommunity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $eventId,
        public readonly int $adminId,
    ) {
    }

    public function handle(): void
    {
        $event = Event::find($this->eventId);
        if (!$event) {
            Log::warning('[PostEventToCommunity] Event not found', ['event_id' => $this->eventId]);
            return;
        }

        // Check if an event post already exists for this event to avoid duplicates
        $exists = CommunityPost::where('event_id', $this->eventId)
            ->where('post_type', 'event')
            ->exists();

        if ($exists) {
            Log::info('[PostEventToCommunity] Event post already exists, skipping', ['event_id' => $this->eventId]);
            return;
        }

        $content = $event->event_description ?: 'Yuk ikut meramaikan event ini! Temukan berbagai produk UMKM lokal dalam satu tempat.';

        $post = CommunityPost::create([
            'user_id'      => $this->adminId,
            'post_title'   => '🎉 Event: ' . $event->event_name,
            'post_content' => $content,
            'post_type'    => 'event',
            'event_id'     => $event->id,
            'post_status'  => 'published',
        ]);

        try {
            broadcast(new CommunityPostCreated($post));
        } catch (\Exception $e) {
            Log::warning('[PostEventToCommunity] Broadcast failed', ['error' => $e->getMessage()]);
        }

        Log::info('[PostEventToCommunity] Event post published to community', [
            'event_id' => $this->eventId,
            'post_id'  => $post->id,
        ]);
    }
}
