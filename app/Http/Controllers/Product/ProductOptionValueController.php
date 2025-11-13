<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductOptionValueController extends Controller
{
    // Add value to option
    public function store(Request $request, Product $product, ProductOption $option)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        $data = $request->validate([
            'option_value' => ['required', 'string', 'max:100'],
        ]);

        if ($option->values()->where('option_value', $data['option_value'])->exists()) {
            return response()->json(['message' => 'Value sudah ada di pilihan ini.'], 422);
        }

        $value = $option->values()->create([
            'option_value' => $data['option_value'],
        ]);

        return response()->json($value, 201);
    }

    // Update value
    public function update(Request $request, Product $product, ProductOption $option, ProductOptionValue $value)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        if ((int) $value->product_option_id !== (int) $option->id) {
            return response()->json([
                'message' => 'Value tidak ditemukan untuk pilihan ini.',
            ], 404);
        }

        $data = $request->validate([
            'option_value' => ['required', 'string', 'max:100'],
        ]);

        if (
            $option->values()
                ->where('option_value', $data['option_value'])
                ->where('id', '!=', $value->id)
                ->exists()
        ) {
            return response()->json(['message' => 'Value sudah ada di pilihan ini.'], 422);
        }

        $value->update($data);

        return response()->json($value);
    }

    // Delete value
    public function destroy(Request $request, Product $product, ProductOption $option, ProductOptionValue $value)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        if ((int) $value->product_option_id !== (int) $option->id) {
            return response()->json([
                'message' => 'Value tidak ditemukan untuk pilihan ini.',
            ], 404);
        }

        $usedInVariants = $value->variants()->exists();
        if ($usedInVariants) {
            return response()->json([
                'message' => 'Tidak dapat menghapus value. Sudah digunakan di variant yang ada.',
            ], 422);
        }

        $value->delete();

        return response()->json(['message' => 'Value dihapus.']);
    }

    // Upload/Replace image untuk option value
    public function storeImage(Request $request, Product $product, ProductOption $option, ProductOptionValue $value)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        if ((int) $value->product_option_id !== (int) $option->id) {
            return response()->json([
                'message' => 'Value tidak ditemukan untuk pilihan ini.',
            ], 404);
        }

        if (!$option->uses_image) {
            return response()->json([
                'message' => 'Pilihan ini tidak dikonfigurasi untuk menggunakan gambar.',
            ], 422);
        }

        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120'],
        ]);

        if ($value->image_path && Storage::disk('public')->exists($value->image_path)) {
            Storage::disk('public')->delete($value->image_path);
        }

        $path = $data['image']->store("option-values/{$value->id}", 'public');
        $value->update(['image_path' => $path]);

        return response()->json(['value' => $value->fresh()], 200);
    }

    // Delete image option value
    public function destroyImage(Request $request, Product $product, ProductOption $option, ProductOptionValue $value)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        if ((int) $value->product_option_id !== (int) $option->id) {
            return response()->json([
                'message' => 'Value tidak ditemukan untuk pilihan ini.',
            ], 404);
        }

        if (!$value->image_path) {
            return response()->json(['message' => 'Value tidak memiliki gambar.'], 404);
        }

        if (Storage::disk('public')->exists($value->image_path)) {
            Storage::disk('public')->delete($value->image_path);
        }

        $value->update(['image_path' => null]);

        return response()->json(['message' => 'Gambar value dihapus.']);
    }
}