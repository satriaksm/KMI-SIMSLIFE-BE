<?php

namespace App\Http\Controllers;

use App\Models\CommunityPost;
use App\Models\CommunityPostImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

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
        $query = CommunityPost::with([
            'user:id,name,profile_picture_path', 
            'images' => function ($query) {
                $query->ordered()->limit(3);
            }])
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
        $perPage = min($request->get('per_page', 15), 100);
        $posts = $query->paginate($perPage);

        return response()->json([
            'items' => $posts->map(fn($post) => $this->formatPostResource($post)),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
                'last_page' => $posts->lastPage(),
            ],
            'links' => [
                'first' => $posts->url(1),
                'last' => $posts->url($posts->lastPage()),
                'prev' => $posts->previousPageUrl(),
                'next' => $posts->nextPageUrl(),
            ],
        ], 200);
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
            'images' => 'nullable|array|max:5|',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ], [
            'post_title.required' => 'The post title field is required.',
            'post_title.max' => 'The post title must not exceed 255 characters.',
            'post_content.required' => 'The post content field is required.',
            'post_status.in' => 'Invalid post status. Must be draft, published, or archived.',
            'images.max' => 'You can upload maximum 5 image per post',
            'images.*.image' => 'Each file must be an image.',
            'images.*.mimes' => 'Images must be in JPEG, PNG, JPG, GIF, or WebP format.',
            'images.*.max' => 'Each image must not exceed 2MB.',
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

            if ($request->hasFile('images')) {
                $this->uploadPostImages($post, $request->file('images'));
            }

            // Load relationships
            $post->load([
                'user:id,name,profile_picture_path',
                'images' => fn($q) => $q->ordered()
            ]);

            $resource = $this->formatPostResource($post, true);

            return response()->json($resource, 201)
                ->header('Location', route('community.posts.show', ['slug' => $post->post_slug]));

        } catch (\Exception $e) {
            if (isset($post)) {
                $post->delete();
            }

            return response()->json([
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

        $post = CommunityPost::with([
            'user:id,name,profile_picture_path',
            'images' => fn($q) => $q->ordered()
            ])
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
        $post->refresh();

        return response()->json($this->formatPostResource($post, true), 200);
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
        $post = CommunityPost::find($id);

        if (!$post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

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
            'new_images' => 'nullable|array|max:5',
            'remove_image_ids' => 'nullable|array',
            'remove_image_ids.*' => 'integer|exists:community_post_images,id',
        ], [
            'post_title.max' => 'The post title must not exceed 255 characters.',
            'post_status.in' => 'Invalid post status. Must be draft, published, or archived.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $updateData = [];
            if ($request->filled('postTitle')) {
                $updateData['post_title'] = $request->postTitle;
            }
            if ($request->filled('postContent')) {
                $updateData['post_content'] = $request->postContent;
            }
            if ($request->filled('postStatus')) {
                $updateData['post_status'] = $request->postStatus;
            }

            if (!empty($updateData)) {
                $post->update($updateData);
            }

            // Remove images if requested
            if ($request->filled('remove_image_ids')) {
                $this->removePostImages($post, $request->remove_image_ids);
            }

            // Upload new images
            if ($request->hasFile('new_images')) {
                $currentImagesCount = $post->images()->count();
                $newImagesCount = count($request->file('new_images'));
                
                if ($currentImagesCount + $newImagesCount > 5) {
                    return response()->json([
                        'message' => 'Cannot upload new images. Maximum 5 images per post.',
                    ], 422);
                }

                $this->uploadPostImages($post, $request->file('new_images'), null, $currentImagesCount);
            }

            $post->load([
                'user:id,name,profile_picture_path',
                'images' => fn($q) => $q->ordered()
            ]);

            return response()->json($this->formatPostResource($post, true), 200);

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

        if (!$post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        // Check authorization
        if ($post->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $post->delete();

        return response()->json(null, 200);
    }

    /**
     * Get Popular Post
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

        $posts = CommunityPost::with([
            'user:id,name,profile_picture_path',
            'images' => fn($q) => $q->ordered()->limit(1)
            ])
            ->published()
            ->popular($limit)
            ->get();

        return response()->json([
            'items' => $posts->map(fn($post) => $this->formatPostResource($post)),
            'meta' => [
                'count' => $posts->count(),
                'limit' => $limit,
            ],
        ], 200);
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
        $perPage = min($request->get('per_page', 15), 100);

        $posts = CommunityPost::with([
            'user:id,name,profile_picture_path',
            'images' => fn($q) => $q->ordered()->limit(3)
            ])
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'items' => $posts->map(fn($post) => $this->formatPostResource($post)),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
                'last_page' => $posts->lastPage(),
            ],
            'links' => [
                'first' => $posts->url(1),
                'last' => $posts->url($posts->lastPage()),
                'prev' => $posts->previousPageUrl(),
                'next' => $posts->nextPageUrl(),
            ],
        ], 200);
    }

        /**
     * Upload images for a post.
     *
     * @param CommunityPost $post
     * @param array $images
     * @param string|null $altPrefix Optional prefix for alt text (defaults to slug of post title)
     * @param int|null $startIndex Optional starting index to continue numbering (defaults to current images count)
     * @return void
     */
        private function uploadPostImages(CommunityPost $post, array $images, ?string $altPrefix = null, ?int $startIndex = null): void
    {
        // Determine starting count for image numbering
        $currentCount = $startIndex !== null ? (int) $startIndex : $post->images()->count();
        $postTitleSlug = $altPrefix ?? Str::slug($post->post_title);

        foreach ($images as $index => $image) {
            $filename = Str::random(20) . '.' . $image->getClientOriginalExtension();
            $path = "community/posts/{$post->id}";
            $fullPath = $image->storeAs($path, $filename, 'public');

            // Auto-generate alt text: {altPrefix}-{n}
            $imageNumber = $currentCount + $index + 1;
            $altText = "{$postTitleSlug}-{$imageNumber}";

            CommunityPostImage::create([
                'post_id' => $post->id,
                'post_image_path' => $fullPath,
                'alt_text' => $altText,
            ]);
        }
    }

    /**
     * Remove images from post
     */
    private function removePostImages(CommunityPost $post, array $imageIds): void
    {
        $images = CommunityPostImage::where('post_id', $post->id)
            ->whereIn('id', $imageIds)
            ->get();

        foreach ($images as $image) {
            // Delete file from storage
            if (Storage::disk('public')->exists($image->post_image_path)) {
                Storage::disk('public')->delete($image->post_image_path);
            }
            // Delete record
            $image->delete();
        }
    }

    /**
     * Format post resource for consistent API response
     */
    private function formatPostResource(CommunityPost $post, bool $includeAllImages = false): array
    {
        $resource = [
            'id' => $post->id,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_slug' => $post->post_slug,
            'post_status' => $post->post_status,
            'views_count' => $post->views_count,
            'images_count' => $post->images_count,
            'thumbnail_url' => $post->thumbnail_url, // First image
            'created_at' => $post->created_at->toISOString(),
            'updated_at' => $post->updated_at->toISOString(),
            'author' => [
                'id' => $post->user->id,
                'name' => $post->user->name,
                'profile_picture' => $post->user->profile_picture_path
                    ? asset('storage/' . $post->user->profile_picture_path)
                    : null,
            ],
            'links' => [
                'self' => url("/api/community/posts/{$post->post_slug}"),
            ],
        ];

        // Include all images (for detail view)
        if ($includeAllImages || $post->relationLoaded('images')) {
            $resource['images'] = $post->images->map(function ($image, $index) {
                return [
                    'id' => $image->id,
                    'image_url' => $image->image_url,
                    'alt_text' => $image->alt_text,
                    'is_primary' => $index === 0, // First image is primary/thumbnail
                    'created_at' => $image->created_at->toISOString(),
                ];
            })->toArray();
        }

        if ($includeAllImages) {
            $resource['links']['author'] = url("/api/users/{$post->user->id}");
        }

        return $resource;
    }

}
