<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportReason extends Model
{
    protected $fillable = [
        'reason_title',
        'applies_to',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function reports()
    {
        return $this->hasMany(ContentReport::class, 'report_reason_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('applies_to', $type);
    }
}