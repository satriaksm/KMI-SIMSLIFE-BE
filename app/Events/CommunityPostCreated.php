<?php

namespace App\Events;

use App\Models\CommunityPost;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a new community post is created.
 * Uses a public channel so all connected users are notified.
 */
class CommunityPostCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CommunityPost $post)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('community')];
    }

    public function broadcastAs(): string
    {
        return 'community.post.created';
    }

    public function broadcastWith(): array
    {
        $this->post->load(['user:id,name,profile_picture_path,is_super_admin', 'user.roles:id,name', 'event:id,event_name,event_start_date,event_end_date,banner_img_path']);

        return [
            'id'         => $this->post->id,
            'post_title' => $this->post->post_title,
            'post_type'  => $this->post->post_type,
            'event_id'   => $this->post->event_id,
            'post_slug'  => $this->post->post_slug,
            'author'     => [
                'id'             => $this->post->user->id,
                'name'           => $this->post->user->name,
                'is_admin'       => $this->post->user->isAdmin(),
                'is_super_admin' => (bool) $this->post->user->is_super_admin,
            ],
            'event' => $this->post->event ? [
                'id'               => $this->post->event->id,
                'event_name'       => $this->post->event->event_name,
                'event_start_date' => $this->post->event->event_start_date,
                'event_end_date'   => $this->post->event->event_end_date,
            ] : null,
            'created_at' => $this->post->created_at->toISOString(),
        ];
    }
}
