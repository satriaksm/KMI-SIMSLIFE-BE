<?php

namespace App\Models;

use App\Models\Addon;
use App\Models\Jasa;
use App\Models\Product;
use Carbon\Carbon;
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
        'cover_path',
        'status',
        'response_at',
        'operational_hours',

        'NPWP',
        'bank_name',
        'bank_account_number',
        'bank_account_name',

        'midtrans_sub_account_id'
    ];

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'response_at' => 'datetime',
        'operational_hours' => 'array',
    ];

    protected $appends = ['logo_url', 'banner_url', 'is_open_now'];

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

    // Relasi ke Jasa
    public function jasas(): HasMany
    {
        return $this->hasMany(Jasa::class, 'merchant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class, 'merchant_id');
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_merchants', 'merchant_id', 'event_id');
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'merchant_id');
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
        return $this->morphMany(Address::class, 'addressable');
    }

    // Alamat utama
    public function primaryAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('label', 'utama')
            ->latest();
    }

    // Accessor untuk Logo URL
    public function getLogoUrlAttribute()
    {
        if (empty($this->logo_path)) {
            return null;
        }

        return route('merchant_profile_pictures.show', [
            'merchant' => $this->id,
            // Query param for cache-busting (no extra path segment)
            'v' => basename((string) $this->logo_path),
        ]);
    }

    // Accessor untuk Banner/Cover URL
    public function getBannerUrlAttribute()
    {
        if (empty($this->cover_path)) {
            return null;
        }

        return route('merchant_banner.show', [
            'merchant' => $this->id,
            // Query param for cache-busting (no extra path segment)
            'v' => basename((string) $this->cover_path),
        ]);
    }

    public function getIsOpenNowAttribute(): bool
    {
        $operationalHours = $this->operational_hours;
        if (!is_array($operationalHours) || empty($operationalHours)) {
            return false;
        }

        $timezone = config('app.timezone') ?: 'UTC';
        $now = Carbon::now($timezone);
        $dayKey = strtolower($now->format('l')); // monday..sunday

        $today = $operationalHours[$dayKey] ?? null;
        if (!is_array($today)) {
            return false;
        }

        if (empty($today['is_open'])) {
            return false;
        }

        $open = $today['open'] ?? null;
        $close = $today['close'] ?? null;
        if (!is_string($open) || !is_string($close) || $open === '' || $close === '') {
            return false;
        }

        try {
            $start = Carbon::parse($now->toDateString() . ' ' . $open, $timezone);
            $end = Carbon::parse($now->toDateString() . ' ' . $close, $timezone);
        } catch (\Throwable $e) {
            return false;
        }

        // Handle overnight schedules (e.g., 20:00 - 02:00)
        if ($end->lessThan($start)) {
            $end->addDay();
        }

        return $now->betweenIncluded($start, $end);
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
