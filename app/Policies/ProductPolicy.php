<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Product;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ProductPolicy
{
    /**
     * ============================
     * CENTRAL PRODUCT PERMISSION
     * ============================
     */
    public function manage(User $user, Product $product): Response
    {
        $merchant = $product->merchant;

        if (!$merchant) {
            return Response::deny('UMKM tidak ditemukan.');
        }

        // 1️⃣ Pastikan relasi product → merchant valid
        if ((int) $product->merchant_id !== (int) $merchant->id) {
            return Response::deny('Produk tidak valid untuk UMKM ini.');
        }

        // 2️⃣ Delegasi izin ke MerchantPolicy
        $ability = Gate::forUser($user)->inspect('manageProduct', $merchant);
        if ($ability->denied()) {
            return Response::deny($ability->message() ?? 'Anda tidak memiliki izin mengelola produk.');
        }

        return Response::allow();
    }

    /**
     * ============================
     * READ
     * ============================
     */

    // public function view(User $user, Product $product): bool
    // {
    //     return $this->manage($user, $product)->allowed();
    // }

    // /**
    //  * ============================
    //  * WRITE
    //  * ============================
    //  */
    // public function update(User $user, Product $product): bool
    // {
    //     return $this->manage($user, $product)->allowed();
    // }

    // public function delete(User $user, Product $product): bool
    // {
    //     return $this->manage($user, $product)->allowed();
    // }
}
