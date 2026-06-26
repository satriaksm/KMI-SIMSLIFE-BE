<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Image extends Model
{
    protected $table = 'images';

    protected $fillable = [
        'imageable_type',
        'imageable_id',
        'image_path',
        'display_order',
        'is_cover',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_cover' => 'boolean',
    ];

    protected $appends = ['url', 'src_url', 'urls', 'src_urls'];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getUrlAttribute()
    {
        return route('images.show', ['image' => $this->id]);
    }

    public function getUrlsAttribute(): array
    {
        return [
            'original' => route('images.show', ['image' => $this->id, 'size' => 'original']),
            'medium' => route('images.show', ['image' => $this->id, 'size' => 'medium']),
            'thumb' => route('images.show', ['image' => $this->id, 'size' => 'thumb']),
        ];
    }

    public function getSrcUrlAttribute(): ?string
    {
        return route('images.show', ['image' => $this->id]);
    }

    public function getSrcUrlsAttribute(): array
    {
        return [
            'original' => route('images.show', ['image' => $this->id, 'size' => 'original']),
            'medium' => route('images.show', ['image' => $this->id, 'size' => 'medium']),
            'thumb' => route('images.show', ['image' => $this->id, 'size' => 'thumb']),
        ];
    }
}
