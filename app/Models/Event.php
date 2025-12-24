<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
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

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'published')
            ->where('event_start_date', '<=', now())
            ->where('event_end_date', '>=', now());
    }
}