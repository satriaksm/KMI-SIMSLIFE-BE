<?php

namespace App\Http\Controllers;

use App\Models\CommunityPost;
use App\Models\PostComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PostCommentController
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
            'user:id,name,profile_picture_path',
            'replies.user:id,name,profile_picture_path',
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

            $comment->load(['user:id,name,profile_picture_path']);

            return response()->json($this->formatCommentResource($comment), 201);
        } catch (\Exception $e) {
            Log::error('Failed to create comment: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create comment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
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

            $comment->load(['user:id,name,profile_picture_path', 'replyToUser:id,name']);

            return response()->json($this->formatCommentResource($comment), 201);
        } catch (\Exception $e) {
            Log::error('Failed to create reply: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create reply',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a specific comment
     */
    public function destroy($postId, $commentId)
    {
        $post = CommunityPost::find($postId);
        if (!$post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        $comment = PostComment::where('id', $commentId)
            ->where('post_id', $postId)
            ->first();

        if (!$comment) {
            return response()->json([
                'message' => 'Comment not found or does not belong to this post',
            ], 404);
        }

        $user = Auth::user();
        if ($comment->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized. You can only delete your own comments.',
            ], 403);
        }

        try {
            $comment->delete();

            return response()->json([
                'success' => 'Comment deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to delete comment: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete comment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
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
            ->with(['user:id,name,profile_picture_path', 'replyToUser:id,name'])
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
            'user:id,name,profile_picture_path',
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
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'profile_picture' => $comment->user->profile_picture,
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
