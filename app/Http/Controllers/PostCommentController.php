<?php

namespace App\Http\Controllers;

use App\Models\PostComment;
use App\Models\CommunityPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PostCommentController extends Controller
{
    /**
     * Get comments for a specific post 
     */
    public function index($postId, Request $request)
    {
        $post = CommunityPost::published()->find($postId);

        if (!$post) {
            return response()->json([
                'message' => 'Post not found or not published',
            ], 404);
        }

        $sort = $request->get('sort', 'oldest');

        // Only load top-level comments with their direct replies
        $query = $post->topLevelComments()->with([
            'user:id,name,profile_picture_path,is_super_admin,updated_at',
            'user.roles:id,name',
            'replies.user:id,name,profile_picture_path,is_super_admin,updated_at',
            'replies.user.roles:id,name',
            'replies.replyToUser:id,name', 
        ]);

        if ($sort === 'newest') {
            $query->latest('created_at');
        } else {
            $query->oldest('created_at');
        }

        $perPage = min($request->get('per_page', 10), 50);
        $comments = $query->paginate($perPage);

        return response()->json([
            'post' => [
                'id' => $post->id,
                'post_title' => $post->post_title,
                'post_slug' => $post->post_slug,
                'comments_count' => $post->comments_count,
            ],
            'comments' => $comments->map(fn($comment) => $this->formatCommentResource($comment, true)),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
                'last_page' => $comments->lastPage(),
            ],
            'links' => [
                'first' => $comments->url(1),
                'last' => $comments->url($comments->lastPage()),
                'prev' => $comments->previousPageUrl(),
                'next' => $comments->nextPageUrl(),
            ],
        ], 200);
    }

    /**
     * Create new comment for a post
     */
    public function store($postId, Request $request)
    {
        $post = CommunityPost::published()->find($postId);
        if (!$post) {
            return response()->json([
                'message' => 'Post not found or not published',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'comment_content' => 'required|string|max:1000|min:1',
        ], [
            'comment_content.required' => 'Comment content is required.',
            'comment_content.max' => 'Comment must not exceed 1000 characters.',
            'comment_content.min' => 'Comment cannot be empty.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $comment = PostComment::create([
                'post_id' => $postId,
                'user_id' => Auth::id(),
                'parent_id' => null,
                'reply_to_user_id' => null,
                'comment_content' => trim($request->comment_content),
            ]);

            $comment->load(['user:id,name,profile_picture_path,is_super_admin', 'user.roles:id,name']);

            // Notify post owner (if not commenting on own post)
            try {
                $post->load('user:id');
                if ($post->user_id && $post->user_id !== Auth::id()) {
                    broadcast(new \App\Events\CommunityCommentCreated(
                        $comment,
                        $post->user_id,
                        'comment_on_post'
                    ))->toOthers();
                }
            } catch (\Exception $broadcastEx) {
                Log::warning('[Community] Comment broadcast failed', ['error' => $broadcastEx->getMessage()]);
            }

            return response()->json($this->formatCommentResource($comment), 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create comment',
            ], 500);

            // Log error internally only
            Log::error('Failed to create comment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * All replies go to root parent, with @mention tracking
     */
    public function reply($postId, $commentId, Request $request)
    {
        $post = CommunityPost::published()->find($postId);
        if (!$post) {
            return response()->json([
                'message' => 'Post not found or not published',
            ], 404);
        }

        $parentComment = PostComment::with('user:id,name')
            ->where('id', $commentId)
            ->where('post_id', $postId)
            ->first();

        if (!$parentComment) {
            return response()->json([
                'message' => 'Comment not found or does not belong to this post',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'comment_content' => 'required|string|max:1000|min:1',
        ], [
            'comment_content.required' => 'Comment content is required.',
            'comment_content.max' => 'Comment must not exceed 1000 characters.',
            'comment_content.min' => 'Comment cannot be empty.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $rootParent = $parentComment->getRootParent();

            $comment = PostComment::create([
                'post_id' => $postId,
                'user_id' => Auth::id(),
                'parent_id' => $rootParent->id,
                'reply_to_user_id' => $parentComment->user_id, 
                'comment_content' => trim($request->comment_content),
            ]);

            $comment->load(['user:id,name,profile_picture_path,is_super_admin,updated_at', 'user.roles:id,name', 'replyToUser:id,name']);

            // Notify parent comment owner
            try {
                if ($parentComment->user_id && $parentComment->user_id !== Auth::id()) {
                    broadcast(new \App\Events\CommunityCommentCreated(
                        $comment,
                        $parentComment->user_id,
                        'reply_to_comment'
                    ))->toOthers();
                }
            } catch (\Exception $broadcastEx) {
                Log::warning('[Community] Reply broadcast failed', ['error' => $broadcastEx->getMessage()]);
            }

            return response()->json($this->formatCommentResource($comment), 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create reply',
            ], 500);

            // Log error internally only
            Log::error('Failed to create reply', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);
        }
    }


    /**
     * Delete Comment (User - Own Comment Only)
     * DELETE /api/comments/{id}
     */
    public function destroy($id)
    {
        $comment = PostComment::findOrFail($id);

        if (!$comment) {
            return response()->json(['message' => 'Comment not found'], 404);
        }

        // Check authorization
        if ($comment->user_id !== Auth::id()) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $comment->delete(); // soft delete

        return response()->json([
            'message' => 'Comment deleted successfully'
        ], 200);
    }

    /**
     * Delete Comment (Admin - Any Comment)
     * DELETE /admin/comments/{id}
     */
    public function adminDestroy(Request $request, $id)
    {
        // Verify admin role
        $admin = $request->user();
        if (!$admin || !$admin->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $comment = PostComment::findOrFail($id);

        if (!$comment) {
            return response()->json(['message' => 'Comment not found'], 404);
        }

        // Log admin action
        \App\Models\AdminAction::create([
            'admin_id' => $admin->id,
            'action_type' => 'delete_content',
            'target_type' => PostComment::class,
            'target_id' => $comment->id,
            'reason' => $request->input('reason', 'Deleted by admin'),
            'metadata' => [
                'comment_excerpt' => substr($comment->comment, 0, 100),
                'comment_author_id' => $comment->user_id,
                'post_id' => $comment->post_id,
            ],
        ]);

        $comment->delete(); // soft delete

        return response()->json([
            'message' => 'Comment deleted successfully by admin'
        ], 200);
    }

    /**
     * Get replies for a specific comment (with pagination)
     */
    public function getReplies($postId, $commentId, Request $request)
    {
        $post = CommunityPost::published()->find($postId);
        if (!$post) {
            return response()->json([
                'message' => 'Post not found or not published',
            ], 404);
        }

        $comment = PostComment::with(['user:id,name,profile_picture_path'])
            ->where('id', $commentId)
            ->where('post_id', $postId)
            ->first();

        if (!$comment) {
            return response()->json([
                'message' => 'Comment not found or does not belong to this post',
            ], 404);
        }

        $perPage = min($request->get('per_page', 10), 50);
        $replies = $comment->replies()
            ->with(['user:id,name,profile_picture_path,updated_at', 'replyToUser:id,name'])
            ->oldest('created_at')
            ->paginate($perPage);

        return response()->json([
            'parent_comment' => $this->formatCommentResource($comment, false),
            'replies' => $replies->map(fn($reply) => $this->formatCommentResource($reply, false)),
            'meta' => [
                'current_page' => $replies->currentPage(),
                'per_page' => $replies->perPage(),
                'total' => $replies->total(),
                'last_page' => $replies->lastPage(),
            ],
            'links' => [
                'first' => $replies->url(1),
                'last' => $replies->url($replies->lastPage()),
                'prev' => $replies->previousPageUrl(),
                'next' => $replies->nextPageUrl(),
            ],
        ], 200);
    }

    /**
     * Get my comments across all posts
     */
    public function myComments(Request $request)
    {
        $perPage = min($request->get('per_page', 15), 100);

        $comments = PostComment::with([
            'user:id,name,profile_picture_path,updated_at',
            'post:id,post_title,post_slug',
            'replyToUser:id,name'
        ])
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'comments' => $comments->map(fn($comment) => $this->formatCommentResource($comment, false, true)),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
                'last_page' => $comments->lastPage(),
            ],
            'links' => [
                'first' => $comments->url(1),
                'last' => $comments->url($comments->lastPage()),
                'prev' => $comments->previousPageUrl(),
                'next' => $comments->nextPageUrl(),
            ],
        ], 200);
    }

    // ================== PRIVATE HELPER METHODS ==================

    /**
     * Format comment resource (Instagram style)
     */
    private function formatCommentResource(PostComment $comment, bool $includeReplies = false, bool $includePost = false): array
    {
        $resource = [
            'id' => $comment->id,
            'comment_content' => $comment->comment_content,
            'is_reply' => $comment->is_reply,
            'replies_count' => $comment->replies_count,
            'created_at' => $comment->created_at->toISOString(),
            'updated_at' => $comment->updated_at->toISOString(),
            'author' => [
                'id'                   => $comment->user->id,
                'name'                 => $comment->user->name,
                'profile_picture'      => $comment->user->profile_picture,
                'profile_picture_urls' => $comment->user->profile_picture_urls,
                'is_admin'             => $comment->user->isAdmin(),
                'is_super_admin'       => (bool) $comment->user->is_super_admin,
            ],
        ];

        // Include @mention info if replying to someone
        if ($comment->reply_to_user_id && $comment->relationLoaded('replyToUser')) {
            $resource['replying_to'] = [
                'user_id' => $comment->replyToUser->id,
                'username' => $comment->replyToUser->name,
            ];
        }

        // Include flat replies 
        if ($includeReplies && $comment->relationLoaded('replies')) {
            $resource['replies'] = $comment->replies->map(
                fn($reply) => $this->formatCommentResource($reply, false)
            )->values();
        }

        // Include post info if requested
        if ($includePost && $comment->relationLoaded('post')) {
            $resource['post'] = [
                'id' => $comment->post->id,
                'title' => $comment->post->post_title,
                'slug' => $comment->post->post_slug,
                'url' => url("/api/community/posts/{$comment->post->post_slug}"),
            ];
        }

        return $resource;
    }
}
