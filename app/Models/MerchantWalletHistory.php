<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantWalletHistory extends Model
{
    protected $fillable = [
        'merchant_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
