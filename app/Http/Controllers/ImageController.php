<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function show(Request $request, Image $image)
    {
        // 1. CEK SIGNED URL (PENTING UNTUK DRAFT)
        // Jika URL memiliki tanda tangan valid dari Laravel, langsung izinkan stream.
        // Ini memintas kebutuhan login/token di header request.
        if ($request->hasValidSignature()) {
            return $this->stream($image);
        }

        $imageable = $image->imageable;

        if ($imageable instanceof Product) {

            // 2. LOGIKA BARU: Published DAN Archived adalah PUBLIC
            if (in_array($imageable->status, ['published', 'archived'])) {
                return $this->stream($image);
            }

            // 3. Jika status DRAFT, cek ownership
            // Masalah: Browser biasa tidak mengirim token di sini, jadi $user sering null.
            // Maka dari itu langkah no. 1 (Signed URL) di atas sangat krusial.
            $user = $request->user();

            if ($user && $imageable->merchant && $user->id === $imageable->merchant->user_id) {
                return $this->stream($image);
            }

            return response()->json(['message' => 'Tidak boleh mengakses gambar ini (Draft)'], 403);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function stream(Image $image)
    {
        $disk = config('filesystems.product_disk', 'private'); // Pastikan disk sesuai config

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
}
