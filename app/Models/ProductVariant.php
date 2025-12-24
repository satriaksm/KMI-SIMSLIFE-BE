<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id',
        'stock',
        'sku',
        'price',
    ];
    protected $appends = ['display_image']; // tambahkan accessor

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductOptionValue::class,
            'product_variant_option_values',
            'product_variant_id',
            'product_option_value_id'
        )->withTimestamps();
    }
    /**
     * Display image: ambil dari option value yang uses_images=true
     * Fallback ke product cover jika tidak ada
     */

    public function getDisplayImageAttribute(): ?string
    {
        // 1. Ambil image dari option value yang pakai image (misal: Warna)
        $imageValue = $this->optionValues()
            ->whereHas('option', fn($q) => $q->where('uses_image', true))
            ->whereNotNull('image_path')
            ->first();

        if ($imageValue) {
            return asset('storage/' . $imageValue->image_path);
        }

        // 2. Fallback ke product cover
        if ($this->product->coverImage) {
            return asset('storage/' . $this->product->coverImage->image_path);
        }

        return null;
    }
}
