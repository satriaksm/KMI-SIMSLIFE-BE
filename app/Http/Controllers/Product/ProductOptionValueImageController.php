<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\ProductOptionValue;
use Illuminate\Support\Facades\Storage;
use App\Services\ImageOptimizationService;

class ProductOptionValueImageController extends Controller
{
    public function show(Request $request, ProductOptionValue $optionValue)
    {
        $size = $request->query('size', 'original');
        // cobalah beberapa kemungkinan relasi
        $product = $optionValue->option?->product
            ?? $optionValue->product // jika ada relasi langsung
            ?? $optionValue->productOption?->product; // fallback

        if (!$product instanceof Product) {
            Log::warning('Product not found for option value', ['optionValue_id' => $optionValue->id]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // normalisasi status (case-insensitive)
        $status = strtolower((string) $product->status);

        if ($status === 'published' || $status === 'publish') {
            return $this->stream($optionValue, $size);
        }

        $user = $request->user();
        if ($user && $product->merchant && $user->id === $product->merchant->user_id) {
            return $this->stream($optionValue, $size);
        }

        Log::info('Access denied to option image', [
            'optionValue_id' => $optionValue->id,
            'product_id' => $product->id,
            'product_status' => $product->status,
            'request_user' => $user?->id,
        ]);

        return response()->json(['message' => 'Tidak boleh mengakses gambar ini'], 403);
    }


    private function stream(ProductOptionValue $optionValue, string $size = 'original')
    {
        $disk = config('filesystems.product_disk', 'private');
        $originalPath = $optionValue->image_path;

        if (!$originalPath) {
            abort(404);
        }

        $path = app(ImageOptimizationService::class)->resolveSizePath($originalPath, $size);

        if (!Storage::disk($disk)->exists($path)) {
            $path = $originalPath; // fallback
            if (!Storage::disk($disk)->exists($path)) {
                abort(404);
            }
        }

        $stream = Storage::disk($disk)->readStream($path);
        $mime = pathinfo($path, PATHINFO_EXTENSION) === 'webp' ? 'image/webp' : 'image/jpeg';

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
