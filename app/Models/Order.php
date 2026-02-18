<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'address_id',
        'user_id',
        'merchant_id',
        'voucher_id',
        'order_code',
        'subtotal',
        'discount_total',
        'gross_amount',
        'delivery_fee_snapshot',

        'status',
        'responsed_at',
        'paid_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',

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
}
