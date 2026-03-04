<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingSetting extends Model
{
    //
    protected $fillable = [
        'base_cost',
        'cost_per_km',
        'status',
    ];
}
