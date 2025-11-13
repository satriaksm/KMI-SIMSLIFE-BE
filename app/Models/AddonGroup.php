<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddonGroup extends Model
{
    protected $fillable = [
        'product_id',
        'addon_group_name',
        'selection_type',
        'min_selection',
        'max_selection',
    ];

    protected $casts = [
        'min_selection' => 'integer',
        'max_selection' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(AddonGroupOption::class);
    }
}