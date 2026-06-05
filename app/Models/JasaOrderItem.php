<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JasaOrderItem extends Model
{
    use HasFactory;

    protected $table = 'jasa_order_items';

    protected $fillable = [
        'order_id',
        'jasa_id',
        'service_order_id',
        'service_consultation_id',
        'quantity',
        'price',
        'subtotal',
        'booking_date',
        'booking_time',
        'service_type',
        'service_type_booking',
        'note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'booking_date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function jasa(): BelongsTo
    {
        return $this->belongsTo(Jasa::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function serviceConsultation(): BelongsTo
    {
        return $this->belongsTo(ServiceConsultation::class, 'service_consultation_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->serviceConsultation();
    }
}
