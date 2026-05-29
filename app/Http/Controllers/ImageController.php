<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Image;
use App\Models\Jasa;
use App\Models\Product;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function show(Request $request, Image $image)
    {
        // 1. CEK SIGNED URL (PENTING UNTUK DRAFT)
        // Jika URL memiliki tanda tangan valid dari Laravel, langsung izinkan stream.
        // Ini memintas kebutuhan login/token di header request.
        if ($request->hasValidSignature()) {
            return $this->stream($image, $this->resolveDiskForImage($image));
        }

        $imageable = $image->imageable;

        if ($imageable instanceof Product) {

            // 2. LOGIKA BARU: Published DAN Archived adalah PUBLIC
            if (in_array($imageable->status, ['published', 'archived'])) {
                return $this->stream($image, $this->resolveDiskForImage($image));
            }

            // 3. Jika status DRAFT, cek ownership
            // Masalah: Browser biasa tidak mengirim token di sini, jadi $user sering null.
            // Maka dari itu langkah no. 1 (Signed URL) di atas sangat krusial.
            $user = $request->user();

            if ($user && $imageable->merchant && $user->id === $imageable->merchant->user_id) {
                return $this->stream($image, $this->resolveDiskForImage($image));
            }

            return response()->json(['message' => 'Tidak boleh mengakses gambar ini (Draft)'], 403);
        }

        if ($imageable instanceof Jasa) {

            // Published/Active/Archived bersifat public untuk customer
            if (
                in_array($imageable->status, ['published', 'active', 'archived'], true)
                || ($imageable->status === null && (bool) $imageable->is_active)
            ) {
                return $this->stream($image, $this->resolveDiskForImage($image));
            }

            // Draft/non-public: cek ownership
            $user = $request->user();
            $imageable->loadMissing('merchant:id,user_id');

            if ($user && $imageable->merchant && (int) $user->id === (int) $imageable->merchant->user_id) {
                return $this->stream($image, $this->resolveDiskForImage($image));
            }

            return response()->json(['message' => 'Tidak boleh mengakses gambar ini (Draft)'], 403);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    public function cartSnapshot(Request $request, CartItem $cartItem)
    {
        if ($request->hasValidSignature()) {
            return $this->streamSnapshot($cartItem);
        }

        $userId = $request->user()?->id ?? Auth::id();
        abort_if(!$userId, 401, 'Unauthenticated');

        $cartItem->loadMissing('cart:id,user_id');
        abort_if((int) $cartItem->cart?->user_id !== (int) $userId, 403, 'Forbidden');

        return $this->streamSnapshot($cartItem);
    }

    public function orderSnapshot(Request $request, \App\Models\ProductOrderItem $orderItem)
    {
        if ($request->hasValidSignature()) {
            return $this->streamSnapshot($orderItem);
        }

        $userId = $request->user()?->id ?? Auth::id();
        abort_if(!$userId, 401, 'Unauthenticated');

        $orderItem->loadMissing(['order.merchant']);
        $order = $orderItem->order;
        
        abort_if(!$order, 404, 'Order not found');

        $isCustomer = (int) $order->user_id === (int) $userId;
        $isMerchant = $order->merchant && (int) $order->merchant->user_id === (int) $userId;

        abort_if(!$isCustomer && !$isMerchant, 403, 'Forbidden');

        return $this->streamSnapshot($orderItem);
    }

    private function resolveDiskForImage(Image $image): string
    {
        $type = (string) ($image->imageable_type ?? '');

        // Jasa images disimpan di disk public (storage/app/public/...)
        if ($type === 'jasa' || str_ends_with($type, '\\Jasa')) {
            return 'public';
        }

        // Default: mengikuti konfigurasi product
        return config('filesystems.product_disk', 'private');
    }

    private function stream(Image $image, string $disk)
    {
        // Pastikan disk sesuai tipe image

        if (!Storage::disk($disk)->exists($image->image_path)) {
            abort(404);
        }

        $stream = Storage::disk($disk)->readStream($image->image_path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $image->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    private function streamSnapshot($item)
    {
        $path = $item->image_snapshot_path;
        abort_if(empty($path), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        abort_if(!$disk->exists($path), 404);

        $stream = $disk->readStream($path);
        $mime = $disk->mimeType($path) ?: 'image/jpeg';

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=31536000',
        ]);
    }
}
