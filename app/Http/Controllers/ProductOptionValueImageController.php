<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\ProductOptionValue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Container\Attributes\Log;

class ProductOptionValueImageController extends Controller
{
    public function show(Request $request, ProductOptionValue $optionValue)
    {
        // cobalah beberapa kemungkinan relasi
        $product = $optionValue->option?->product
            ?? $optionValue->product // jika ada relasi langsung
            ?? $optionValue->productOption?->product; // fallback

        if (!$product instanceof Product) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // normalisasi status (case-insensitive)
        $status = strtolower((string) $product->status);

        if ($status === 'published' || $status === 'publish') {
            return $this->stream($optionValue);
        }

        $user = $request->user();
        if ($user && $product->merchant && $user->id === $product->merchant->user_id) {
            return $this->stream($optionValue);
        }


        return response()->json(['message' => 'Tidak boleh mengakses gambar ini'], 403);
    }


    private function stream(ProductOptionValue $optionValue)
    {
        $disk = config('filesystems.product_disk', 'private');
        $imagePath = $optionValue->image_path;

        if (!$imagePath || !Storage::disk($disk)->exists($imagePath)) {
            abort(404);
        }

        $stream = Storage::disk($disk)->readStream($imagePath);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
