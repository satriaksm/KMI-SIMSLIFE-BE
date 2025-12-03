<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'reply_to_user_id', 
        'comment_content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'replies_count',
        'is_reply',
    ];

    /**
     * Get the post that owns the conent.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    /**
     * Get the user who wrote the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent comment (if this is a reply).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'parent_id');
    }

    /**
     * Get all replies to this comment.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(PostComment::class, 'parent_id')
            ->with(['user:id,name,profile_picture_path', 'replyToUser:id,name'])
            ->oldest('created_at');
    }

    /**
     * Get direct replies only (1 level deep).
     */
    public function directReplies(): HasMany
    {
        return $this->hasMany(PostComment::class, 'parent_id')->with(['user:id,name,profile_picture_path']);
    }

    /**
     * Get the user being replied to (for nested replies)
     */
    public function replyToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }

    /**
     * Check if this comment is a reply to another comment.
     */
    public function getIsReplyAttribute(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Get count of all replies (including nested).
     */
    public function getRepliesCountAttribute(): int
    {
        return $this->replies()->count();
    }

    /**
     * Scope: Get only top-level comments (no parent).
     */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: Get comments for a specific post.
     */
    public function scopeForPost($query, $postId)
    {
        return $query->where('post_id', $postId);
    }

    /**
     * Scope: Get recent comments first.
     */
    public function scopeRecent($query)
    {
        return $query->latest('created_at');
    }

    /**
     * Scope: Get oldest comments first.
     */
    public function scopeOldest($query)
    {
        return $query->oldest('created_at');
    }

    /**
     * Get all descendants (replies and their replies) recursively.
     */
    public function getAllReplies()
    {
        return $this->replies()->with('replies');
    }

    /**
     * Get the root comment (top-level parent).
     */
    public function getRootComment()
    {
        if ($this->parent_id === null) {
            return $this;
        }

        return $this->parent->getRootComment();
    }

    /**
     * Get the root parent comment (for deeply nested replies).
     */
    public function getRootParent()
    {
        if ($this->parent_id === null) {
            return $this;
        }
        return $this->parent->getRootParent();
    }

    /**
     * Get nested level of this comment (0 = top level, 1 = first reply, etc).
     */
    public function getNestingLevel(): int
    {
        if ($this->parent_id === null) {
            return 0;
        }

        return $this->parent->getNestingLevel() + 1;
    }
}
