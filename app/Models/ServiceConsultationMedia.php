<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ServiceConsultationMedia extends Model
{
    use HasFactory;

    protected $table = 'service_consultation_media';

    protected $fillable = [
        'service_consultation_id',
        'file_name',
        'file_path',
        'file_url',
        'file_type',
        'mime_type',
        'file_size',
        'display_order',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(ServiceConsultation::class, 'service_consultation_id');
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    public function getFileUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }

        if ($this->file_path) {
            return Storage::url($this->file_path);
        }

        return null;
    }

    public function getMediaUrlAttribute(): ?string
    {
        return $this->file_url;
    }

    public function getIsImageAttribute(): bool
    {
        return $this->file_type === 'image';
    }

    public function getIsVideoAttribute(): bool
    {
        return $this->file_type === 'video';
    }

    /**
     * Generate storage path for consultation media.
     */
    public static function generatePath(string $fileName, string $type = 'images'): string
    {
        $date = now()->format('Y/m/d');
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $newFileName = $baseName . '_' . uniqid() . '.' . $extension;

        return "service-consultations/{$type}/{$date}/{$newFileName}";
    }
}