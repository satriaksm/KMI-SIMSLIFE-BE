<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JasaSubcategory extends Model
{
    use HasFactory;

    protected $table = 'jasa_subcategories';

    protected $fillable = [
        'jasa_category_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(JasaCategory::class, 'jasa_category_id');
    }

    public function jasas()
    {
        return $this->hasMany(Jasa::class, 'jasa_subcategory_id');
    }
}
