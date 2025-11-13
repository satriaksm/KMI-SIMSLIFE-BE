<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Image extends Model
{
    protected $table = 'images';

    protected $fillable = [
        'image_path',
        'display_order',
        'is_cover'
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_cover' => 'boolean',
    ];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
