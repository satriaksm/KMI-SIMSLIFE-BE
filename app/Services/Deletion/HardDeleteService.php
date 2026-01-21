<?php

namespace App\Services\Deletion;

use App\Models\Cart;
use App\Models\CommunityPost;
use App\Models\Image;
use App\Models\Jasa;
use App\Models\JasaImage;
use App\Models\Merchant;
use App\Models\PostComment;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HardDeleteService
{
    public function deleteUser(User $user): void
    {
        // 0) Community content (must be deleted via Eloquent to trigger model hooks that delete files)
        CommunityPost::query()
            ->where('user_id', $user->id)
            ->get()
            ->each
            ->delete();

        // Delete user's comments on other users' posts.
        // We try to preserve other users' replies by detaching them first.
        $userCommentIds = PostComment::query()
            ->where('user_id', $user->id)
            ->pluck('id');

        if ($userCommentIds->isNotEmpty()) {
            PostComment::query()
                ->whereIn('parent_id', $userCommentIds)
                ->update(['parent_id' => null]);

            PostComment::query()
                ->whereIn('id', $userCommentIds)
                ->delete();
        }

        // 0.1) Cart (DB cascades will clear items/addons)
        Cart::query()->where('user_id', $user->id)->delete();

        // 1) Delete all merchants (and their dependent data)
        $user->loadMissing('merchants');
        foreach ($user->merchants as $merchant) {
            $this->deleteMerchant($merchant);
        }

        // 2) Delete user-owned assets
        $this->deleteFromDiskIfExists('public', $user->profile_picture_path);

        // 3) Cleanup auth/pivots that may block deletion
        // (best-effort: if any relation doesn't exist in schema, it won't be called)
        try {
            $user->tokens()->delete();
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $user->roles()->detach();
        } catch (\Throwable $e) {
            // ignore
        }

        // Delete all user addresses (polymorphic)
        $user->addresses()->delete();

        // 4) Finally delete the user
        $user->delete();
    }

    public function deleteMerchant(Merchant $merchant): void
    {
        $merchant->loadMissing(['products.images', 'addresses', 'vouchers.usages']);

        // A) Merchant assets
        $this->deleteFromDiskIfExists('public', $merchant->logo_path);
        $this->deleteFromDiskIfExists('public', $merchant->cover_path);

        // B) Products (delete files first, then DB)
        $productDisk = config('filesystems.product_disk', 'public');
        foreach ($merchant->products as $product) {
            // Delete polymorphic images (images table)
            foreach ($product->images as $img) {
                $this->deleteFromAnyDiskIfExists([$productDisk, 'public'], $img->image_path);
            }

            // Best-effort: remove whole product folder to clean any option-value images
            $this->deleteDirectoryFromAnyDiskIfExists([$productDisk, 'public'], "products/{$product->id}");

            $product->delete();
        }

        // C) Jasa (delete files first, then DB)
        $jasas = Jasa::query()->where('merchant_id', $merchant->id)->get(['id', 'merchant_id']);
        foreach ($jasas as $jasa) {
            // Delete jasa_images files (public disk)
            $jasaImages = JasaImage::query()->where('jasa_id', $jasa->id)->get(['id', 'path']);
            foreach ($jasaImages as $ji) {
                $this->deleteFromDiskIfExists('public', $ji->path);
            }

            // Best-effort: delete any polymorphic images referencing Jasa (if used)
            $polyImages = Image::query()
                ->where('imageable_type', Jasa::class)
                ->where('imageable_id', $jasa->id)
                ->get(['id', 'image_path']);
            foreach ($polyImages as $pi) {
                $this->deleteFromDiskIfExists('public', $pi->image_path);
            }

            // Remove directory
            $this->deleteDirectoryFromDiskIfExists('public', "jasa/{$jasa->id}");

            // Cleanup dependent rows that are safe to remove
            try {
                $jasa->packages()->delete();
            } catch (\Throwable $e) {
                // ignore
            }

            $jasa->delete();
        }

        // D) Vouchers
        foreach ($merchant->vouchers as $voucher) {
            // If a voucher has usages, deleting it is still safe due to FK cascade,
            // but in many flows you may want to prevent deletion. Here we proceed.
            try {
                $voucher->merchantsVoucher()->detach();
            } catch (\Throwable $e) {
                // ignore
            }

            $voucher->delete();
        }

        // E) Events pivot
        try {
            $merchant->events()->detach();
        } catch (\Throwable $e) {
            // ignore
        }

        // F) Addresses
        $merchant->addresses()->delete();

        // G) Finally delete merchant
        $merchant->delete();
    }

    private function deleteFromAnyDiskIfExists(array $disks, ?string $path): void
    {
        if (!$path) {
            return;
        }

        $path = ltrim($path, '/');

        foreach ($disks as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    return;
                }
            } catch (\Throwable $e) {
                // ignore and try next disk
            }
        }

        Log::debug('[HardDeleteService] File not found on any disk', [
            'path' => $path,
            'disks' => $disks,
        ]);
    }

    private function deleteDirectoryFromAnyDiskIfExists(array $disks, string $dir): void
    {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return;
        }

        foreach ($disks as $disk) {
            try {
                if (Storage::disk($disk)->exists($dir)) {
                    Storage::disk($disk)->deleteDirectory($dir);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    private function deleteFromDiskIfExists(string $disk, ?string $path): void
    {
        if (!$path) {
            return;
        }

        $path = ltrim($path, '/');

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function deleteDirectoryFromDiskIfExists(string $disk, string $dir): void
    {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($dir)) {
                Storage::disk($disk)->deleteDirectory($dir);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
