<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductOptionValue extends Model
{
    protected $table = 'product_option_values';

    protected $fillable = [
        'product_option_id',
        'option_value',
        'image_path',
    ];
    protected $appends = ['image_url', 'image_urls'];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }
        return route('images.product-option-value.show', [
            'optionValue' => $this->id,
            'v' => $this->updated_at ? $this->updated_at->timestamp : time()
        ]);
    }

    public function getImageUrlsAttribute(): ?array
    {
        if (empty($this->image_path)) {
            return null;
        }

        $v = $this->updated_at ? $this->updated_at->timestamp : time();

        return [
            'original' => route('images.product-option-value.show', ['optionValue' => $this->id, 'size' => 'original', 'v' => $v]),
            'medium' => route('images.product-option-value.show', ['optionValue' => $this->id, 'size' => 'medium', 'v' => $v]),
            'thumb' => route('images.product-option-value.show', ['optionValue' => $this->id, 'size' => 'thumb', 'v' => $v]),
        ];
    }

    // Relasi ke variants (many-to-many via pivot)
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_option_values',
            'product_option_value_id',
            'product_variant_id'
        );
    }



    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
