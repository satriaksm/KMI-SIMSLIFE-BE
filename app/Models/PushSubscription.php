<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'audiences',
        'endpoint',
        'p256dh',
        'auth',
        'content_encoding',
        'expiration_time',
    ];

    protected $casts = [
        'audiences' => 'array',
        'expiration_time' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
