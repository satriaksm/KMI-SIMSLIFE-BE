<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewMedia extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'review_media';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'review_id',
        'file_path',
        'file_url',
        'file_type',
        'mime_type',
        'original_name',
        'file_size',
        'display_order',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'file_size' => 'integer',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = ['media_url'];

    /**
     * Get the review that owns the media.
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Rating::class, 'review_id');
    }

    /**
     * Get full URL for the media file.
     */
    public function getMediaUrlAttribute()
    {
        return $this->file_url ?: ($this->file_path ? asset('storage/' . $this->file_path) : null);
    }

    /**
     * Check if file is an image.
     */
    public function isImage(): bool
    {
        return $this->file_type === 'image';
    }

    /**
     * Check if file is a video.
     */
    public function isVideo(): bool
    {
        return $this->file_type === 'video';
    }

    /**
     * Get file extension.
     */
    public function getExtensionAttribute(): string
    {
        if (!$this->file_path) {
            return '';
        }
        return pathinfo($this->file_path, PATHINFO_EXTENSION);
    }

    /**
     * Determine file type from extension.
     */
    public static function determineFileType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $videoExtensions = ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }
        if (in_array($extension, $documentExtensions)) {
            return 'document';
        }

        return 'image'; // Default to image
    }
}
