<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Merchant;
use App\Models\CommunityPost;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class PublicProfileController extends Controller
{
    public function show($id)
    {
        $user = User::select(['id', 'name', 'profile_picture_path', 'created_at'])
            ->findOrFail($id);

        // Get approved merchants
        $merchants = Merchant::where('user_id', $id)
            ->where('status', 'approved')
            ->with(['segmentation', 'primaryAddress'])
            ->get();

        // Get community posts
        $posts = CommunityPost::where('user_id', $id)
            ->where('post_status', 'published')
            ->with(['images' => fn($q) => $q->ordered()->limit(1)])
            ->latest()
            ->get();

        return ApiResponse::success([
            'user' => $user,
            'merchants' => $merchants,
            'posts' => $posts->map(function($post) {
                return [
                    'id' => $post->id,
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_slug' => $post->post_slug,
                    'views_count' => $post->views_count,
                    'created_at' => $post->created_at->toISOString(),
                    'image_id' => $post->images->first()?->id,
                    'thumbnail_url' => $post->thumbnail_url,
                ];
            }),
        ]);
    }
}
