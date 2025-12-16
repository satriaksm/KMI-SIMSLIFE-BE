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
            // Generate slug on create
            if (empty($merchant->slug)) {
                $merchant->slug = static::generateUniqueSlug($merchant->name);
            }
        });

        static::updating(function ($merchant) {
            // Update slug only if name changed and slug is empty or being manually set
            if ($merchant->isDirty('name') && !$merchant->isDirty('slug')) {
                $merchant->slug = static::generateUniqueSlug($merchant->name, $merchant->id);
            }
        });
    }

    /**
     * Generate unique slug
     *
     * @param string $name
     * @param int|null $ignoreId - ID to ignore (for updates)
     * @return string
     */
    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        // Loop until we find unique slug
        while (static::slugExists($slug, $ignoreId)) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     *
     * @param string $slug
     * @param int|null $ignoreId
     * @return bool
     */
    protected static function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $query = static::where('slug', $slug);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }

    // Relasi ke Products
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'merchant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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

    // Banyak alamat 
    public function addresses(): MorphMany
    {
        return $this->morphMany(\App\Models\Adrress::class, 'addressable');
    }

    // Alamat utama
    public function primaryAddress(): MorphOne
    {
        return $this->morphOne(\App\Models\Adrress::class, 'addressable')
            ->where('label', 'utama')
            ->latest();
    }

    // Accessor untuk Logo URL
    public function getLogoUrlAttribute()
    {
        if ($this->logo_path) {
            if (str_starts_with($this->logo_path, 'http')) {
                return $this->logo_path;
            }
            return url('storage/' . $this->logo_path);
        }
        return null;
    }

    /**
     * Scope: Only approved merchants
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope: Only pending merchants
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Only rejected merchants
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
