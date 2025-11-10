<?php

namespace App\Http\Controllers;

use App\Models\CommunityPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CommunityPostController
{
    /**
     * List Community Posts
     *
     * Returns a paginated list of published community posts with optional search filtering.
     *
     * @authenticated
     *
     * @queryParam search string Filter posts by title or content. Example: UMKM
     * @queryParam per_page integer Number of posts per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "user_id": 1,
     *         "post_title": "Inpo tambal ban ke rumah",
     *         "post_content": "inpo tambal ban yg bisa ke rumah",
     *         "post_slug": "inpo-tambal-ban-ke-rumah",
     *         "post_status": "published",
     *         "views_count": 150,
     *         "created_at": "2025-11-10T08:00:00.000000Z",
     *         "updated_at": "2025-11-10T08:00:00.000000Z",
     *         "user": {
     *           "id": 1,
     *           "name": "Me",
     *           "email": "me@example.com"
     *         }
     *       }
     *     ],
     *     "per_page": 15,
     *     "total": 50
     *   }
     * }
     */
    public function index(Request $request)
    {
        $query = CommunityPost::with(['user'])
            ->published()
            ->recent();

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('post_title', 'LIKE', "%{$search}%")
                  ->orWhere('post_content', 'LIKE', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $posts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $posts,
        ]);
    }

    /**
     * Create Community Post
     *
     * Create a new community post. The post slug will be auto-generated from the title.
     *
     * @authenticated
     *
     * @bodyParam post_title string required The title of the post (max: 255 characters). Example: Inpo tambal ban ke rumah
     * @bodyParam post_content string required The content/body of the post. Example: inpo tambal ban yg bisa ke rumah
     * @bodyParam post_status string The status of the post (draft, published, archived). Defaults to published. Example: published
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Post created successfully",
     *   "data": {
     *     "id": 1,
     *     "user_id": 1,
     *     "post_title": "Inpo tambal ban ke rumah",
     *     "post_content": "inpo tambal ban yg bisa ke rumah",
     *     "post_slug": "inpo-tambal-ban-ke-rumah",
     *     "post_status": "published",
     *     "views_count": 0,
     *     "created_at": "2025-11-10T08:00:00.000000Z",
     *     "updated_at": "2025-11-10T08:00:00.000000Z",
     *     "user": {
     *       "id": 1,
     *       "name": "Me",
     *       "email": "me@example.com"
     *     }
     *   }
     * }
     *
     * @response 422 {
     *   "success": false,
     *   "errors": {
     *     "post_title": ["The post title field is required."],
     *     "post_content": ["The post content field is required."]
     *   }
     * }
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'post_title' => 'required|string|max:255',
            'post_content' => 'required|string',
            'post_status' => 'sometimes|in:draft,published,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Create post
            $post = CommunityPost::create([
                'user_id' => Auth::id(),
                'post_title' => $request->post_title,
                'post_content' => $request->post_content,
                'post_status' => $request->post_status ?? 'published',
            ]);

            // Load relationships
            $post->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Post created successfully',
                'data' => $post,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create post',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Community Post
     *
     * Retrieves the details of a specific community post by its slug.
     * This endpoint also increments the view count of the post.
     *
     * @authenticated
     *
     * @urlParam slug string required The slug of the post. Example: inpo-tambal-ban-ke-rumah
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "user_id": 1,
     *     "post_title": "Inpo tambal ban ke rumah",
     *     "post_content": "inpo tambal ban yg bisa ke rumah",
     *     "post_slug": "inpo-tambal-ban-ke-rumah",
     *     "post_status": "published",
     *     "views_count": 151,
     *     "created_at": "2025-11-10T08:00:00.000000Z",
     *     "updated_at": "2025-11-10T08:00:00.000000Z",
     *     "user": {
     *       "id": 1,
     *       "name": "Me",
     *       "email": "me@example.com"
     *     }
     *   }
     * }
     *
     * @response 404 {
     *   "success": false,
     *   "message": "Post not found"
     * }
     */
    public function show($slug)
    {
        $post = CommunityPost::with(['user'])
            ->where('post_slug', $slug)
            ->published()
            ->first();

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        // Increment views
        $post->incrementViews();

        return response()->json([
            'success' => true,
            'data' => $post,
        ]);
    }

    /**
     * Update Community Post
     *
     * Updates a specific community post. Only the post owner can update their post.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the post. Example: 1
     *
     * @bodyParam post_title string The title of the post (max: 255 characters). Example: Inpo tambal ban ke rumah (Updated)
     * @bodyParam post_content string The content/body of the post. Example: inpo tambal ban yg bisa ke rumah (Updated)
     * @bodyParam post_status string The status of the post (draft, published, archived). Example: published
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Post updated successfully",
     *   "data": {
     *     "id": 1,
     *     "user_id": 1,
     *     "post_title": "Inpo tambal ban ke rumah (Updated)",
     *     "post_content": "inpo tambal ban yg bisa ke rumah (Updated)",
     *     "post_slug": "inpo-tambal-ban-ke-rumah",
     *     "post_status": "published",
     *     "views_count": 150,
     *     "created_at": "2025-11-10T08:00:00.000000Z",
     *     "updated_at": "2025-11-10T10:00:00.000000Z",
     *     "user": {
     *       "id": 1,
     *       "name": "Me",
     *       "email": "me@example.com"
     *     }
     *   }
     * }
     *
     * @response 403 {
     *   "success": false,
     *   "message": "Unauthorized"
     * }
     *
     * @response 404 {
     *   "success": false,
     *   "message": "Post not found"
     * }
     */
    public function update(Request $request, $id)
    {
        $post = CommunityPost::findOrFail($id);

        // Check authorization
        if ($post->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'post_title' => 'sometimes|string|max:255',
            'post_content' => 'sometimes|string',
            'post_status' => 'sometimes|in:draft,published,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $post->update($request->only([
                'post_title',
                'post_content',
                'post_status',
            ]));

            $post->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Post updated successfully',
                'data' => $post,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update post',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Community Post
     *
     * Deletes a specific community post. Only the post owner can delete their post.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the post. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Post deleted successfully"
     * }
     *
     * @response 403 {
     *   "success": false,
     *   "message": "Unauthorized"
     * }
     *
     * @response 404 {
     *   "success": false,
     *   "message": "Post not found"
     * }
     */
    public function destroy($id)
    {
        $post = CommunityPost::findOrFail($id);

        // Check authorization
        if ($post->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post deleted successfully',
        ]);
    }

    /**
     * Get Popular Posts
     *
     * Returns a list of most viewed/popular community posts.
     *
     * @authenticated
     *
     * @queryParam limit integer Number of posts to return (default: 10, max: 50). Example: 5
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 1,
     *       "post_title": "Inpo tambal ban ke rumah",
     *       "post_content": "inpo tambal ban yg bisa ke rumah",
     *       "post_slug": "inpo-tambal-ban-ke-rumah",
     *       "post_status": "published",
     *       "views_count": 500,
     *       "created_at": "2025-11-10T08:00:00.000000Z",
     *       "updated_at": "2025-11-10T08:00:00.000000Z",
     *       "user": {
     *         "id": 1,
     *         "name": "John Doe",
     *         "email": "john@example.com"
     *       }
     *     }
     *   ]
     * }
     */
    public function popular(Request $request)
    {
        $limit = min($request->get('limit', 10), 50);

        $posts = CommunityPost::with(['user'])
            ->published()
            ->popular($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $posts,
        ]);
    }

    /**
     * Get My Posts
     *
     * Returns a paginated list of posts created by the authenticated user.
     *
     * @authenticated
     *
     * @queryParam per_page integer Number of posts per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "user_id": 1,
     *         "post_title": "Inpo tambal ban ke rumah",
     *         "post_content": "inpo tambal ban yg bisa ke rumah",
     *         "post_slug": "inpo-tambal-ban-ke-rumah",
     *         "post_status": "published",
     *         "views_count": 25,
     *         "created_at": "2025-11-10T08:00:00.000000Z",
     *         "updated_at": "2025-11-10T08:00:00.000000Z",
     *         "user": {
     *           "id": 1,
     *           "name": "Me",
     *           "email": "me@example.com"
     *         }
     *       }
     *     ],
     *     "per_page": 15,
     *     "total": 5
     *   }
     * }
     */
    public function myPosts(Request $request)
    {
        $query = CommunityPost::with(['user'])
            ->where('user_id', Auth::id())
            ->latest();

        $posts = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $posts,
        ]);
    }
}
