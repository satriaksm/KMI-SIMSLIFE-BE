<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CommunityPostImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'post_image_path',
        'alt_text',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'image_urls',
    ];

    /**
     * Get the post that owns the image.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    /**
     * Get the full image URL.
     */
    public function getImageUrlAttribute(): string
    {
        return route('community-images.show', [
            'image' => $this->id,
            'v' => $this->updated_at ? $this->updated_at->timestamp : time()
        ]);
    }

    /**
     * Get responsive image URLs.
     */
    public function getImageUrlsAttribute(): array
    {
        $v = $this->updated_at ? $this->updated_at->timestamp : time();

        return [
            'original' => route('community-images.show', ['image' => $this->id, 'size' => 'original', 'v' => $v]),
            'medium' => route('community-images.show', ['image' => $this->id, 'size' => 'medium', 'v' => $v]),
            'thumb' => route('community-images.show', ['image' => $this->id, 'size' => 'thumb', 'v' => $v]),
        ];
    }

    /**
     * Ordered by created_at.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('created_at', 'asc');
    }
    
    public function getIsPrimaryAttribute(): bool
    {
        $firstImage = $this->post->images()->ordered()->first();
        return $firstImage && $firstImage->id === $this->id;
    }

}
