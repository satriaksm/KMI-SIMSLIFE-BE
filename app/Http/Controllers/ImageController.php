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
        $imageable = $image->imageable;

        if ($imageable instanceof Product) {
            // publik jika published
            if ($imageable->status === 'published') {
                return $this->stream($image);
            }

            // jika draft/archived => owner saja
            $user = $request->user();
            if ($user && $imageable->merchant && $user->id === $imageable->merchant->user_id) {
                return $this->stream($image);
            }

            return response()->json(['message' => 'Tidak boleh mengakses gambar ini'], 403);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function stream(Image $image)
    {
        $disk = config('filesystems.product_disk', 'private');

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