<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationMessageMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'consultation_message_id',
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
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConsultationMessage::class, 'consultation_message_id');
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public function isImage(): bool
    {
        return in_array($this->file_type, ['image']) ||
               str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function isVideo(): bool
    {
        return in_array($this->file_type, ['video']) ||
               str_starts_with($this->mime_type ?? '', 'video/');
    }

    public function getHumanFileSize(): string
    {
        $bytes = $this->file_size ?? 0;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}