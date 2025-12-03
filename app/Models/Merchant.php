<?php

namespace App\Models;

use App\Models\Addon;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'paguyuban_id',
        'segmentation_id',
        'name',
        'slug',
        'phone',
        'description',
        'logo_path',
        'status',
        'response_at',
    ];

    protected $casts = [
        'response_at' => 'datetime',
    ];

    protected $appends = ['logo_url'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($merchant) {
            if (empty($merchant->slug)) {
                $merchant->slug = Str::slug($merchant->name);

                // Ensure uniqueness
                $count = static::where('slug', 'like', $merchant->slug . '%')->count();
                if ($count > 0) {
                    $merchant->slug = $merchant->slug . '-' . ($count + 1);
                }
            }
        });

        static::updating(function ($merchant) {
            // Update slug jika nama berubah
            if ($merchant->isDirty('name') && empty($merchant->slug)) {
                $merchant->slug = Str::slug($merchant->name);

                // Ensure uniqueness
                $count = static::where('slug', 'like', $merchant->slug . '%')
                    ->where('id', '!=', $merchant->id)
                    ->count();
                if ($count > 0) {
                    $merchant->slug = $merchant->slug . '-' . ($count + 1);
                }
            }
        });
    }

    // ✅ Relasi ke Products
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'merchant_id');
    }

    // Relasi ke user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ✅ Relasi ke Addons
    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class, 'merchant_id');
    }

    // Relasi ke paguyuban
    public function paguyuban(): BelongsTo
    {
        return $this->belongsTo(Paguyuban::class);
    }

    // Relasi ke segmentation
    public function segmentation(): BelongsTo
    {
        return $this->belongsTo(Segmentation::class);
    }

    // Banyak alamat (polimorfik)
    public function addresses(): MorphMany
    {
        return $this->morphMany(\App\Models\Adrress::class, 'addressable');
    }

    // Alamat utama (opsional)
    public function primaryAddress(): MorphOne
    {
        return $this->morphOne(\App\Models\Adrress::class, 'addressable')
            ->where('label', 'utama')
            ->latest();
    }

    // ✅ Accessor untuk Logo URL
    public function getLogoUrlAttribute()
    {
        if ($this->logo_path) {
            // Jika menggunakan storage public
            if (str_starts_with($this->logo_path, 'http')) {
                return $this->logo_path;
            }
            return url('storage/' . $this->logo_path);
        }
        return null;
    }
}
