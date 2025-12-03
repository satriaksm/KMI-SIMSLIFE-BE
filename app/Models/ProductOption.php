<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOption extends Model
{
    protected $table = 'product_options';
    protected $fillable = ['product_id', 'option_name', 'uses_image'];

    protected $casts = [
        'uses_image' => 'boolean',
    ];

    // Relations
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class);
    }

    // ✅ Auto-delete values ketika option dihapus
    protected static function booted()
    {
        static::deleting(function ($option) {
            // Hapus semua values (cascade)
            $option->values()->each(function ($value) {
                // Hapus file image jika ada
                if ($value->image_path && Storage::disk('public')->exists($value->image_path)) {
                    Storage::disk('public')->delete($value->image_path);
                }
                $value->delete();
            });
        });
    }
}