<?php

namespace App\Events;

use App\Models\PostComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the post owner or comment owner when a new comment/reply is added.
 */
class CommunityCommentCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PostComment $comment,
        public int $targetUserId,  // the user to notify (post owner or parent comment owner)
        public string $notifType   // 'comment_on_post' | 'reply_to_comment'
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.' . $this->targetUserId . '.community')];
    }

    public function broadcastAs(): string
    {
        return 'community.comment.created';
    }

    public function broadcastWith(): array
    {
        $this->comment->load([
            'user:id,name,profile_picture_path,is_super_admin',
            'user.roles:id,name',
            'post:id,post_title,post_slug',
        ]);

        return [
            'notif_type'     => $this->notifType,
            'comment_id'     => $this->comment->id,
            'post_id'        => $this->comment->post_id,
            'post_title'     => $this->comment->post?->post_title,
            'post_slug'      => $this->comment->post?->post_slug,
            'comment_content'=> $this->comment->comment_content,
            'is_reply'       => $this->comment->is_reply,
            'author'         => [
                'id'             => $this->comment->user->id,
                'name'           => $this->comment->user->name,
                'is_admin'       => $this->comment->user->isAdmin(),
                'is_super_admin' => (bool) $this->comment->user->is_super_admin,
            ],
            'created_at' => $this->comment->created_at->toISOString(),
        ];
    }
}
