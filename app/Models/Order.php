<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
<<<<<<< HEAD
        'address_id',
=======
        'merchant_id',
        'jasa_id',
>>>>>>> staging-ta
        'user_id',
        'merchant_id',
        'voucher_id',
        'order_code',
        'subtotal',
        'discount_total',
        'platform_fee',
        'gross_amount',
        'net_amount',
        'delivery_fee_snapshot',
        'delivery_type',
        'payment_method',
        'notes',

        'status',
        'responsed_at',
        'accepted_at',
        'rejected_at',
        'paid_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',
        'confirm_deadline',

        'proof_image_path',
        'failed_reason',

        'user_name_snapshot',
        'user_phone_snapshot',
        'address_detail_snapshot',
        'province_name_snapshot',
        'city_name_snapshot',
        'district_name_snapshot',
        'village_name_snapshot',
        'latitude_snapshot',
        'longitude_snapshot',
    ];

<<<<<<< HEAD
    protected $casts = [
        'responsed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'confirm_deadline' => 'datetime',
    ];
=======
    public function jasa()
    {
        return $this->belongsTo(Jasa::class);
    }
>>>>>>> staging-ta

    public function address()
    {
<<<<<<< HEAD
        return $this->belongsTo(Address::class);
=======
        return $this->belongsTo(Package::class);
>>>>>>> staging-ta
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

<<<<<<< HEAD
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items()
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
    
    protected $appends = ['proof_image_url'];

    public function getProofImageUrlAttribute()
    {
        if ($this->proof_image_path) {
            return asset('storage/' . $this->proof_image_path);
        }
        return null;
=======
    public function items()
    {
        return $this->hasMany(OrderItem::class);
>>>>>>> staging-ta
    }
}
