<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Product;

class ProductPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    public function update(User $user, Product $product): bool
    {
        // Cek apakah user adalah owner merchant produk ini
        return (int) $product->merchant->user_id === (int) $user->id;
    }
}