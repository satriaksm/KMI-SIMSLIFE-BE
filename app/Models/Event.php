<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_name',
        'event_description',
        'event_start_date',
        'event_end_date',
        'banner_img_path',
        'status',
        'created_by',
    ];

    protected $casts = [
        'event_start_date' => 'date',
        'event_end_date' => 'date',
    ];

    protected $appends = [
        'banner_url',
        'banner_urls',
    ];

    public function getBannerUrlAttribute()
    {
        if (empty($this->banner_img_path)) {
            return null;
        }
        return route('event-banners.show', ['event' => $this->id]);
    }

    public function getBannerUrlsAttribute()
    {
        if (empty($this->banner_img_path)) {
            return null;
        }

        return [
            'original' => route('event-banners.show', ['event' => $this->id, 'size' => 'original']),
            'medium' => route('event-banners.show', ['event' => $this->id, 'size' => 'medium']),
            'thumb' => route('event-banners.show', ['event' => $this->id, 'size' => 'thumb']),
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function merchants()
    {
        return $this->belongsToMany(Merchant::class, 'event_merchants')
            ->using(EventMerchant::class)
            ->withPivot('status', 'responded_at')
            ->withTimestamps();
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * Scope: Only active events (published & within date range)
     */
    public function scopeActive($query)
    {
        $today = Carbon::today();
        
        return $query->where('status', 'published')
            ->whereDate('event_start_date', '<=', $today)
            ->whereDate('event_end_date', '>=', $today);
    }

    /**
     * Scope: Published events
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Auto-update event statuses based on dates
     * Call this periodically or before fetching events
     */
    public static function autoUpdateStatuses()
    {
        $now = Carbon::now();

        // Archive events that passed end date and still published
        static::where('status', 'published')
            ->whereDate('event_end_date', '<', $now)
            ->update(['status' => 'archived']);

        Log::info('[Event] Auto-archived past events', [
            'date' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if event is currently active
     */
    public function isActive(): bool
    {
        $today = Carbon::today();
        
        return $this->status === 'published'
            && $today->gte($this->event_start_date)
            && $today->lte($this->event_end_date);
    }

    /**
     * Check if event has started
     */
    public function hasStarted(): bool
    {
        return Carbon::today()->gte($this->event_start_date);
    }

    /**
     * Check if event has ended
     */
    public function hasEnded(): bool
    {
        return Carbon::today()->gt($this->event_end_date);
    }

    /**
     * Get appropriate status based on dates
     */
    public function getAutoStatus(): string
    {
        $now = Carbon::now();

        if ($now->gt($this->event_end_date)) {
            return 'archived';
        }

        if ($this->status === 'draft') {
            return 'draft';
        }

        if ($now->gte($this->event_start_date) && $now->lte($this->event_end_date)) {
            return 'published';
        }

        return 'draft';
    }
}