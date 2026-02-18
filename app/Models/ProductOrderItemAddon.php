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
}
