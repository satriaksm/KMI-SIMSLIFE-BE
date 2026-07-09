<?php

namespace App\Listeners;

use App\Events\CommunityCommentCreated;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendCommunityCommentNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /**
     * Create the event listener.
     */
    public function __construct(private WebPushService $webPushService)
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(CommunityCommentCreated $event): void
    {
        $recipient = User::find($event->targetUserId);
        
        // Prevent sending notification to self (e.g. if I reply to my own post)
        if ($recipient && $recipient->id !== $event->comment->user_id) {
            $this->webPushService->notifyCommunityComment($event->comment, $recipient);
        }
    }
}
