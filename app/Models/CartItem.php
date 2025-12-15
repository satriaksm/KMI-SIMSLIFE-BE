<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'itemable_id',
        'itemable_type',
        'product_variant_id',
        'quantity',
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
        return $this->belongsTo(
            ProductVariant::class,
            'product_variant_id' // 👈 WAJIB
        );
    }

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

}
