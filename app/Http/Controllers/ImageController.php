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
        $size = $request->query('size', 'original');
        // 1. CEK SIGNED URL (PENTING UNTUK DRAFT)
        // Jika URL memiliki tanda tangan valid dari Laravel, langsung izinkan stream.
        // Ini memintas kebutuhan login/token di header request.
        if ($request->hasValidSignature()) {
            return $this->stream($image, $this->resolveDiskForImage($image), $size);
        }

        $imageable = $image->imageable;

        if ($imageable instanceof Product) {

            // 2. LOGIKA BARU: Published DAN Archived adalah PUBLIC
            if (in_array($imageable->status, ['published', 'archived'])) {
                return $this->stream($image, $this->resolveDiskForImage($image), $size);
            }

            // 3. Jika status DRAFT, cek ownership
            // Masalah: Browser biasa tidak mengirim token di sini, jadi $user sering null.
            // Maka dari itu langkah no. 1 (Signed URL) di atas sangat krusial.
            $user = $request->user();

            if ($user && $imageable->merchant && $user->id === $imageable->merchant->user_id) {
                return $this->stream($image, $this->resolveDiskForImage($image), $size);
            }

            return response()->json(['message' => 'Tidak boleh mengakses gambar ini (Draft)'], 403);
        }

        if ($imageable instanceof Jasa) {

            // Published/Active/Archived bersifat public untuk customer
            if (
                in_array($imageable->status, ['published', 'active', 'archived'], true)
                || ($imageable->status === null && (bool) $imageable->is_active)
            ) {
                return $this->stream($image, $this->resolveDiskForImage($image), $size);
            }

            // Draft/non-public: cek ownership
            $user = $request->user();
            $imageable->loadMissing('merchant:id,user_id');

            if ($user && $imageable->merchant && (int) $user->id === (int) $imageable->merchant->user_id) {
                return $this->stream($image, $this->resolveDiskForImage($image), $size);
            }

            return response()->json(['message' => 'Tidak boleh mengakses gambar ini (Draft)'], 403);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    public function cartSnapshot(Request $request, CartItem $cartItem)
    {
        $size = $request->query('size', 'original');
        if ($request->hasValidSignature()) {
            return $this->streamSnapshot($cartItem, $size);
        }

        $userId = $request->user()?->id ?? Auth::id();
        abort_if(!$userId, 401, 'Unauthenticated');

        $cartItem->loadMissing('cart:id,user_id');
        abort_if((int) $cartItem->cart?->user_id !== (int) $userId, 403, 'Forbidden');

        return $this->streamSnapshot($cartItem, $size);
    }

    public function orderSnapshot(Request $request, string $orderItemId)
    {
        $orderItem = \App\Models\ProductOrderItem::find($orderItemId) ?? \App\Models\JasaOrderItem::find($orderItemId);
        abort_if(!$orderItem, 404, 'Item not found');

        $size = $request->query('size', 'original');
        if ($request->hasValidSignature()) {
            return $this->streamSnapshot($orderItem, $size);
        }

        $userId = $request->user()?->id ?? Auth::id();
        abort_if(!$userId, 401, 'Unauthenticated');

        $orderItem->loadMissing(['order.merchant']);
        $order = $orderItem->order;
        
        abort_if(!$order, 404, 'Order not found');

        $isCustomer = (int) $order->user_id === (int) $userId;
        $isMerchant = $order->merchant && (int) $order->merchant->user_id === (int) $userId;

        abort_if(!$isCustomer && !$isMerchant, 403, 'Forbidden');

        return $this->streamSnapshot($orderItem, $size);
    }

    public function orderProof(Request $request, \App\Models\Order $order)
    {
        $size = $request->query('size', 'original');
        if ($request->hasValidSignature()) {
            return $this->streamOrderProof($order, $size);
        }

        $userId = $request->user()?->id ?? Auth::id();
        abort_if(!$userId, 401, 'Unauthenticated');

        $order->loadMissing(['merchant']);
        
        $isCustomer = (int) $order->user_id === (int) $userId;
        $isMerchant = $order->merchant && (int) $order->merchant->user_id === (int) $userId;

        abort_if(!$isCustomer && !$isMerchant, 403, 'Forbidden');

        return $this->streamOrderProof($order, $size);
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

    private function stream(Image $image, string $disk, string $size = 'original')
    {
        $path = $this->resolveSizePath($image->image_path, $size);
        $stream = null;
        
        if (Storage::disk($disk)->exists($path)) {
            $stream = Storage::disk($disk)->readStream($path);
        } else if (file_exists(public_path($path))) {
            $stream = fopen(public_path($path), 'r');
        } else {
            $path = $image->image_path; // fallback
            if (Storage::disk($disk)->exists($path)) {
                $stream = Storage::disk($disk)->readStream($path);
            } else if (file_exists(public_path($path))) {
                $stream = fopen(public_path($path), 'r');
            } else {
                abort(404);
            }
        }

        $mime = pathinfo($path, PATHINFO_EXTENSION) === 'webp' ? 'image/webp' : ($image->mime_type ?? 'image/jpeg');

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    private function streamSnapshot($item, string $size = 'original')
    {
        $snapshotPath = $item->image_snapshot_path ?? $item->jasa_image_snapshot;
        $path = $this->resolveSizePath($snapshotPath, $size);
        abort_if(empty($path), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        
        if (!$disk->exists($path)) {
            $path = $snapshotPath;
            abort_if(!$disk->exists($path), 404);
        }

        $stream = $disk->readStream($path);
        $mime = pathinfo($path, PATHINFO_EXTENSION) === 'webp' ? 'image/webp' : ($disk->mimeType($path) ?: 'image/jpeg');

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=31536000',
        ]);
    }

    private function streamOrderProof($order, string $size = 'original')
    {
        $path = $this->resolveSizePath($order->proof_image_path, $size);
        abort_if(empty($path), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        
        if (!$disk->exists($path)) {
            $path = $order->proof_image_path;
            abort_if(!$disk->exists($path), 404);
        }

        $stream = $disk->readStream($path);
        $mime = pathinfo($path, PATHINFO_EXTENSION) === 'webp' ? 'image/webp' : ($disk->mimeType($path) ?: 'image/jpeg');

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=31536000',
        ]);
    }

    private function resolveSizePath(?string $path, string $size): ?string
    {
        if (empty($path) || $size === 'original') {
            return $path;
        }

        if ($size === 'thumb') {
            return preg_replace('/\.([a-zA-Z0-9]+)$/', '_thumb.$1', $path);
        } elseif ($size === 'medium') {
            return preg_replace('/\.([a-zA-Z0-9]+)$/', '_medium.$1', $path);
        }

        return $path;
    }
}
