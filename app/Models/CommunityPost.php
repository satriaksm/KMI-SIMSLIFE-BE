<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommunityPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'post_title',
        'post_content',
        'post_slug',
        'post_status',
        'views_count',
    ];

    protected $casts = [
        'views_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate slug on creating
        static::creating(function ($post) {
            if (empty($post->post_slug)) {
                $post->post_slug = Str::slug($post->post_title);
                $originalSlug = $post->post_slug;
                $count = 1;
                while (static::where('post_slug', $post->post_slug)->exists()) {
                    $post->post_slug = "{$originalSlug}-{$count}";
                    $count++;
                }
            }
        });
    }

    /**
     * Get the user that owns the post.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // /**
    //  * Get all comments for the post.
    //  */
    // public function comments(): HasMany
    // {
    //     return $this->hasMany(Comment::class, 'post_id');
    // }

    // /**
    //  * Get only top-level comments (no parent).
    //  */
    // public function topLevelComments(): HasMany
    // {
    //     return $this->hasMany(Comment::class, 'post_id')
    //         ->whereNull('parent_id')
    //         ->with(['user', 'replies.user', 'reactions'])
    //         ->latest();
    // }

    // /**
    //  * Get all images for the post.
    //  */
    // public function images(): HasMany
    // {
    //     return $this->hasMany(PostImage::class, 'post_id')->orderBy('sort_order');
    // }


    // /**
    //  * Get total comments count.
    //  */
    // public function getCommentsCountAttribute()
    // {
    //     return $this->comments()->count();
    // }

    /**
     * Increment views count.
     */
    public function incrementViews()
    {
        $this->increment('views_count');
    }

    /**
     * Scope: Published posts only.
     */
    public function scopePublished($query)
    {
        return $query->where('post_status', 'published');
    }


    /**
     * Scope: Popular posts (by views).
     */
    public function scopePopular($query, $limit = 10)
    {
        return $query->orderBy('views_count', 'desc')->limit($limit);
    }

    /**
     * Scope: Recent posts.
     */
    public function scopeRecent($query)
    {
        return $query->latest('created_at');
    }
}
