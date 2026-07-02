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
        return route('images.show', [
            'image' => $this->id,
            'v' => $this->updated_at ? $this->updated_at->timestamp : time()
        ]);
    }

    public function getUrlsAttribute(): array
    {
        $v = $this->updated_at ? $this->updated_at->timestamp : time();

        return [
            'original' => route('images.show', ['image' => $this->id, 'size' => 'original', 'v' => $v]),
            'medium' => route('images.show', ['image' => $this->id, 'size' => 'medium', 'v' => $v]),
            'thumb' => route('images.show', ['image' => $this->id, 'size' => 'thumb', 'v' => $v]),
        ];
    }

    public function getSrcUrlAttribute(): ?string
    {
        return route('images.show', [
            'image' => $this->id,
            'v' => $this->updated_at ? $this->updated_at->timestamp : time()
        ]);
    }

    public function getSrcUrlsAttribute(): array
    {
        $v = $this->updated_at ? $this->updated_at->timestamp : time();

        return [
            'original' => route('images.show', ['image' => $this->id, 'size' => 'original', 'v' => $v]),
            'medium' => route('images.show', ['image' => $this->id, 'size' => 'medium', 'v' => $v]),
            'thumb' => route('images.show', ['image' => $this->id, 'size' => 'thumb', 'v' => $v]),
        ];
    }
}
