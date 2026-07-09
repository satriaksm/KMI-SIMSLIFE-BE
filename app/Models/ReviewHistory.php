<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewHistory extends Model
{
    protected $table = 'review_histories';

    protected $fillable = [
        'rating_id',
        'old_rating',
        'old_title',
        'old_comment',
        'old_media',
        'new_rating',
        'new_title',
        'new_comment',
        'new_media',
        'updated_by',
    ];

    protected $casts = [
        'old_rating' => 'integer',
        'new_rating' => 'integer',
        'old_media' => 'array',
        'new_media' => 'array',
    ];

    /**
     * Get the rating this history belongs to.
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    /**
     * Get the user who made the update.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
