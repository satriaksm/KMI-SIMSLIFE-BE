<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Addon extends Model
{
    protected $fillable = [
        'merchant_id',
        'addon_name',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    // Relation ke addon_groups via pivot
    public function addonGroups(): BelongsToMany
    {
        return $this->belongsToMany(AddonGroup::class, 'addon_group_options')
            ->withPivot('addon_price', 'addon_stock')
            ->withTimestamps();
    }
}