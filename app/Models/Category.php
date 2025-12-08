<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany; // changed

class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'image_path'
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): MorphToMany // changed
    {
        return $this->morphedByMany(Product::class, 'categorizable')->withTimestamps();
    }

    // Contoh untuk model lain (Service), aktifkan jika modelnya ada
    // public function services(): MorphToMany
    // {
    //     return $this->morphedByMany(Service::class, 'categorizable')->withTimestamps();
    // }
}
