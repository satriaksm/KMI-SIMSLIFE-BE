<?php
// filepath: c:\laragon\www\KMI-SIMSLIFE-BE\app\Policies\MerchantPolicy.php

namespace App\Policies;

use App\Models\User;
use App\Models\Merchant;

class MerchantPolicy
{
    /**
     * Determine if user can update merchant (owner only)
     */
    public function update(User $user, Merchant $merchant): bool
    {
        return (int) $merchant->user_id === (int) $user->id;
    }

    /**
     * Determine if user can view merchant data
     */
    public function view(User $user, Merchant $merchant): bool
    {
        return (int) $merchant->user_id === (int) $user->id;
    }

    /**
     * Determine if user can delete merchant
     */
    public function delete(User $user, Merchant $merchant): bool
    {
        return (int) $merchant->user_id === (int) $user->id;
    }
}
