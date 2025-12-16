<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItemAddon extends Model
{
    protected $fillable = [
        'cart_item_id',
        'addon_group_id',
        'addon_id',
    ];

    public function cartItem()
    {
        return $this->belongsTo(CartItem::class);
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }

    public function addonGroup()
    {
        return $this->belongsTo(AddonGroup::class);
    }

    public function addonGroupOption()
    {
        return $this->hasOne(AddonGroupOption::class, 'addon_id', 'addon_id')
            ->where('addon_group_options.addon_group_id', $this->addon_group_id);
    }
}
