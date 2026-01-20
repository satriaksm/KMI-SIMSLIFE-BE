<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Merchant;
use App\Models\Voucher;
use Illuminate\Auth\Access\Response;

class VoucherPolicy
{
    /**
     * Merchant owner authorization (replacement for VoucherController::authorizeMerchant)
     */
    public function manageMerchant(User $user, Merchant $merchant): Response
    {
        if ((int) $merchant->user_id !== (int) $user->id) {
            return Response::deny('Forbidden');
        }

        return Response::allow();
    }

    /**
     * Merchant: list vouchers
     */
    public function viewAny(User $user, Merchant $merchant): Response
    {
        return $this->manageMerchant($user, $merchant);
    }

    /**
     * Merchant: create voucher
     */
    public function create(User $user, Merchant $merchant): Response
    {
        return $this->manageMerchant($user, $merchant);
    }

    /**
     * Merchant: view voucher detail
     */
    public function view(User $user, Voucher $voucher, Merchant $merchant): Response
    {
        return $this->manageMerchant($user, $merchant);
    }

    /**
     * Merchant: update voucher
     */
    public function update(User $user, Voucher $voucher, Merchant $merchant): Response
    {
        return $this->manageMerchant($user, $merchant);
    }

    /**
     * Merchant: delete voucher
     */
    public function delete(User $user, Voucher $voucher, Merchant $merchant): Response
    {
        return $this->manageMerchant($user, $merchant);
    }
}
