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
    protected $appends = ['image_url', 'urls', 'thumb_url', 'medium_url'];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    public function getUrlsAttribute(): ?array
    {
        if (empty($this->image_path)) {
            return null;
        }

        $imageService = app(\App\Services\ImageOptimizationService::class);
        return [
            'original' => asset('storage/' . $this->image_path),
            'medium' => asset('storage/' . $imageService->resolveSizePath($this->image_path, 'medium')),
            'thumb' => asset('storage/' . $imageService->resolveSizePath($this->image_path, 'thumb')),
        ];
    }

    public function getThumbUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }
        $imageService = app(\App\Services\ImageOptimizationService::class);
        return asset('storage/' . $imageService->resolveSizePath($this->image_path, 'thumb'));
    }

    public function getMediumUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }
        $imageService = app(\App\Services\ImageOptimizationService::class);
        return asset('storage/' . $imageService->resolveSizePath($this->image_path, 'medium'));
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
