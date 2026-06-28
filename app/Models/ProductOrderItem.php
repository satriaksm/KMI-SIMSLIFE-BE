<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'product_variant_snapshot',
        'sku_snapshot',
        'image_snapshot_path',
        'quantity',
        'unit_price_snapshot',
        'subtotal_snapshot',
    ];

    protected $appends = [
        'is_reviewed',
        'can_review',
        'can_update_review',
        'price',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function service()
    {
        return $this->belongsTo(Jasa::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function addons()
    {
        return $this->hasMany(ProductOrderItemAddon::class);
    }

    public function review()
    {
        return $this->hasOne(Rating::class, 'order_item_id');
    }

    public function getPriceAttribute($value)
    {
        return $value !== null ? (float)$value : (float)($this->unit_price_snapshot ?? 0);
    }

    public function getIsReviewedAttribute(): bool
    {
        return $this->relationLoaded('review')
            ? $this->review !== null
            : $this->review()->exists();
    }

    public function getCanReviewAttribute(): bool
    {
        $status = null;
        if ($this->relationLoaded('order') && $this->order) {
            $status = $this->order->status;
        } elseif ($this->order_id) {
            $status = \Illuminate\Support\Facades\DB::table('orders')
                ->where('id', $this->order_id)
                ->value('status');
        }

        if (!$status) {
            return false;
        }
        $status = strtolower($status);
        $isCompleted = in_array($status, ['completed', 'selesai']);
        return $isCompleted && !$this->is_reviewed;
    }

    public function getCanUpdateReviewAttribute(): bool
    {
        $review = $this->relationLoaded('review')
            ? $this->review
            : $this->review()->first();

        if (!$review) {
            return false;
        }
        return method_exists($review, 'canUpdate') ? $review->canUpdate() : (int)($review->update_count ?? 0) < 1;
    }
}