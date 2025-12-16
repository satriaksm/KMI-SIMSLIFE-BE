<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JasaCategory extends Model
{
    use HasFactory;

    protected $table = 'jasa_categories';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function jasas()
    {
        return $this->hasMany(Jasa::class, 'jasa_category_id');
    }

    public function subcategories()
    {
        return $this->hasMany(JasaSubcategory::class, 'jasa_category_id');
    }
}
