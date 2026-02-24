<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOrderItemAddon extends Model
{
    protected $fillable = [
        'product_order_item_id',
        'addon_id',
        'addon_name_snapshot',
        'addon_price_snapshot',
    ];

    public function item()
    {
        return $this->belongsTo(ProductOrderItem::class, 'product_order_item_id');
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }
}
