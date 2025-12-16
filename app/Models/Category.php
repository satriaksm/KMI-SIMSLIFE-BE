<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Category extends Model
{
    use HasFactory;
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

    public function products(): MorphToMany
    {
        return $this->morphedByMany(
            Product::class,
            'categorizable',
            'categorizables',
            'category_id',
            'categorizable_id'
        )->withTimestamps();
    }

    // public function services(): MorphToMany
    // {
    //     return $this->morphedByMany(
    //         Service::class,
    //         'categorizable',
    //         'categorizables',
    //         'category_id',
    //         'categorizable_id'
    //     )->withTimestamps();
    // }
}
