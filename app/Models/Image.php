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

    protected $appends = [
        'url',
        'src_url',
        'urls',
        'src_urls',
        'thumb_url',
        'medium_url',
        'original_url',
    ];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getUrlAttribute(): ?string
    {
        return $this->attributes['url'] ?? ($this->id ? route('images.show', ['image' => $this->id]) : null);
    }

    public function getSrcUrlAttribute(): ?string
    {
        return $this->attributes['src_url'] ?? $this->getUrlAttribute();
    }

    public function getUrlsAttribute(): array
    {
        if (isset($this->attributes['urls'])) {
            return is_array($this->attributes['urls'])
                ? $this->attributes['urls']
                : json_decode($this->attributes['urls'], true);
        }

        if (!$this->id) {
            return [
                'original' => null,
                'medium' => null,
                'thumb' => null,
            ];
        }

        $v = $this->updated_at ? $this->updated_at->timestamp : time();

        return [
            'original' => route('images.show', ['image' => $this->id, 'size' => 'original', 'v' => $v]),
            'medium' => route('images.show', ['image' => $this->id, 'size' => 'medium', 'v' => $v]),
            'thumb' => route('images.show', ['image' => $this->id, 'size' => 'thumb', 'v' => $v]),
        ];
    }

    public function getSrcUrlsAttribute(): array
    {
        return $this->attributes['src_urls'] ?? $this->getUrlsAttribute();
    }

    public function getThumbUrlAttribute(): ?string
    {
        return $this->attributes['thumb_url'] ?? ($this->urls['thumb'] ?? null);
    }

    public function getMediumUrlAttribute(): ?string
    {
        return $this->attributes['medium_url'] ?? ($this->urls['medium'] ?? null);
    }

    public function getOriginalUrlAttribute(): ?string
    {
        return $this->attributes['original_url'] ?? ($this->urls['original'] ?? null);
    }
}
