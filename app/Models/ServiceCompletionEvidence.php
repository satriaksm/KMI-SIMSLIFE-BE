<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ServiceCompletionEvidence extends Model
{
    use HasFactory;

    protected $table = 'service_completion_evidences';

    // Append accessors to array/json output
    protected $appends = ['file_url', 'media_url', 'is_image', 'is_video'];

    protected $fillable = [
        'jasa_order_item_id',
        'service_order_id',
        'file_name',
        'file_path',
        'file_url',
        'file_type',
        'mime_type',
        'file_size',
        'display_order',
    ];

    protected $casts = [
        'jasa_order_item_id' => 'integer',
        'file_size' => 'integer',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================================
    // FILE VALIDATION CONSTANTS
    // ============================================================
    public const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    public const ALLOWED_VIDEO_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm'];
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;    // 5MB
    public const MAX_VIDEO_SIZE = 50 * 1024 * 1024;    // 50MB

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    /**
     * @deprecated Gunakan jasaOrderItem() sebagai gantinya.
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /**
     * Relasi ke JasaOrderItem.
     * Gunakan ini sebagai pengganti serviceOrder().
     */
    public function jasaOrderItem(): BelongsTo
    {
        return $this->belongsTo(JasaOrderItem::class, 'jasa_order_item_id');
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
            // Use asset() to generate full URL (not Storage::url() which returns relative path)
            return asset('storage/' . ltrim($this->file_path, '/'));
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

    // ============================================================
    // HELPER METHODS
    // ============================================================

    /**
     * Generate storage path for completion evidence.
     * Stored at: storage/app/public/services/completions/
     */
    public static function generatePath(string $fileName, string $type = 'images'): string
    {
        $date = now()->format('Y/m/d');
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $newFileName = $baseName . '_' . uniqid() . '.' . $extension;

        return "services/completions/{$type}/{$date}/{$newFileName}";
    }

    /**
     * Determine file type from mime type.
     */
    public static function getFileType(string $mimeType): string
    {
        return str_starts_with($mimeType, 'image/') ? 'image' : 'video';
    }

    /**
     * Validate a file for upload.
     * Returns null if valid, error message if invalid.
     */
    public static function validateFile($file): ?string
    {
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $extension = strtolower($file->getClientOriginalExtension());

        // Check extension
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            return 'Tipe file tidak diizinkan. Gunakan: ' . implode(', ', self::ALLOWED_EXTENSIONS);
        }

        // Check image constraints
        if (in_array($mimeType, self::ALLOWED_IMAGE_TYPES)) {
            if ($size > self::MAX_IMAGE_SIZE) {
                return 'Ukuran foto maksimal 5MB';
            }
        }

        // Check video constraints
        if (in_array($mimeType, self::ALLOWED_VIDEO_TYPES)) {
            if ($size > self::MAX_VIDEO_SIZE) {
                return 'Ukuran video maksimal 50MB';
            }
        }

        return null;
    }
}
