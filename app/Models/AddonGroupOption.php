<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddonGroupOption extends Model
{
    protected $fillable = [
        'addon_group_id',
        'addon_id',
        'addon_price',
    ];

    protected $casts = [
        'addon_price' => 'decimal:2',
    ];

    public function addonGroup(): BelongsTo
    {
        return $this->belongsTo(AddonGroup::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
