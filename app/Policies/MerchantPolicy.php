<?php
// filepath: c:\laragon\www\KMI-SIMSLIFE-BE\app\Policies\MerchantPolicy.php

namespace App\Policies;

use App\Models\User;
use App\Models\Merchant;
use Illuminate\Auth\Access\Response;

class MerchantPolicy
{
    private const ALLOWED_SEGMENT_IDS = [1, 2];

    /**
     * Determine if user can update merchant (owner only)
     */
    public function update(User $user, Merchant $merchant): bool
    {
        // 1) Owner check
        if ((int) $merchant->user_id !== (int) $user->id) {
            return false;
        }

        // 2) Status approved
        if (($merchant->status ?? null) !== 'approved') {
            return false;
        }

        return true;
    }

    /**
     * Determine if user can manage products for this merchant.
     * Returns Response so caller can get a useful deny message.
     */
    public function manageProduct(User $user, Merchant $merchant): Response
    {
        // 1) Owner check
        if ((int) $merchant->user_id !== (int) $user->id) {
            return Response::deny('UMKM tidak sah. Anda bukan pemilik UMKM ini.');
        }

        // 2) Status approved
        if (($merchant->status ?? null) !== 'approved') {
            return Response::deny('UMKM belum disetujui oleh admin.');
        }

        // 3) Segment allowed
        $segmentId = $merchant->segmentation_id
            ?? $merchant->segment_id
            ?? optional($merchant->segmentation)->id
            ?? null;

        if (!in_array((int) $segmentId, self::ALLOWED_SEGMENT_IDS, true)) {
            return Response::deny('Segment UMKM tidak diizinkan untuk mengelola produk.');
        }

        return Response::allow();
    }

    /**
     * Determine if user can view merchant data
     */
    public function view(User $user, Merchant $merchant): bool
    {
        // 1) Owner check
        if ((int) $merchant->user_id !== (int) $user->id) {
            return false;
        }

        // 2) Status approved
        if (($merchant->status ?? null) !== 'approved') {
            return false;
        }

        return true;
    }

    /**
     * Determine if user can delete merchant
     */
    public function delete(User $user, Merchant $merchant): bool
    {
        // 1) Owner check
        if ((int) $merchant->user_id !== (int) $user->id) {
            return false;
        }

        // 2) Status approved
        if (($merchant->status ?? null) !== 'approved') {
            return false;
        }
        return true;
    }
}
