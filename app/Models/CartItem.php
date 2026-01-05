<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'itemable_id',
        'itemable_type',

        // 🔒 UMUM (SEMUA ITEM)
        'itemable_name_snapshot',
        'price_snapshot',
        'quantity',

        // 🔒 KHUSUS PRODUCT
        'product_variant_id',
        'product_variant_name_snapshot',

        // 🔒 SNAPSHOT COVER IMAGE
        'image_snapshot_path',

    ];
    public function itemable()
    {
        return $this->morphTo();
    }

    public function addons()
    {
        return $this->hasMany(CartItemAddon::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }


    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }



}
