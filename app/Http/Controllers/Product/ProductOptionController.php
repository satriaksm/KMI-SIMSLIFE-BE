<?php

namespace App\Http\Controllers\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\ProductOption;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class ProductOptionController extends Controller
{
    private const MAX_OPTIONS = 2;

    // List options + values for product
    public function index(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        return response()->json(
            $product->load('options.values')
        );
    }

    // Create option + values
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'option_name' => ['required', 'string', 'max:100'],
            'values' => ['required', 'array', 'min:1'],
            'values.*' => ['required', 'string', 'max:100'],
        ]);

        // Validasi: maksimal 2 option per product
        $currentOptionCount = $product->options()->count();
        if ($currentOptionCount >= self::MAX_OPTIONS) {
            return response()->json([
                'message' => 'Produk hanya dapat memiliki maksimal ' . self::MAX_OPTIONS . ' pilihan.',
            ], 422);
        }

        // Cek duplikat option_name (unique per product)
        if ($product->options()->where('option_name', $data['option_name'])->exists()) {
            return response()->json(['message' => 'Nama pilihan sudah ada.'], 422);
        }

        // ✅ AUTO: Option pertama uses_image=true, sisanya false
        $isFirstOption = $currentOptionCount === 0;

        $option = $product->options()->create([
            'option_name' => $data['option_name'],
            'uses_image' => $isFirstOption, // otomatis true untuk option pertama
        ]);

        // Buat values
        foreach ($data['values'] as $val) {
            $option->values()->create(['option_value' => $val]);
        }

        return response()->json($option->load('values'), 201);
    }

    // Update option name + uses_image
    public function update(Request $request, Product $product, ProductOption $option)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        $data = $request->validate([
            'option_name' => ['sometimes', 'required', 'string', 'max:100'],
            // ❌ uses_image tidak bisa diubah manual (auto-managed)
        ]);

        // Cek duplikat option_name (kecuali diri sendiri)
        if (
            isset($data['option_name']) &&
            $product->options()
                ->where('option_name', $data['option_name'])
                ->where('id', '!=', $option->id)
                ->exists()
        ) {
            return response()->json(['message' => 'Nama pilihan sudah ada.'], 422);
        }

        $option->update($data);

        return response()->json($option->fresh('values'));
    }

    // Delete option (cascade delete values + detach dari variants)
    public function destroy(Request $request, Product $product, ProductOption $option)
    {
        $this->authorize('update', $product);

        if ((int) $option->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Pilihan tidak ditemukan untuk produk ini.',
            ], 404);
        }

        // Cek apakah ini option pertama (yang uses_image=true)
        $firstOption = $product->options()->orderBy('id')->first();
        $isFirstOption = $firstOption && (int) $firstOption->id === (int) $option->id;

        DB::transaction(function () use ($option, $product, $isFirstOption) {
            // Hapus option (cascade delete values via model event)
            $option->delete();

            // ✅ Jika option yang dihapus adalah option pertama:
            // Promosikan option kedua jadi uses_image=true
            if ($isFirstOption) {
                $newFirstOption = $product->options()->orderBy('id')->first();

                if ($newFirstOption) {
                    $newFirstOption->update(['uses_image' => true]);

                    // ⚠️ Jika ada image di values option lama, hapus imagenya
                    // (opsional, tergantung business rule)
                }
            }

            // ✅ Cleanup: Hapus semua image_path di values option yang dihapus
            // (sudah auto-cascade jika pakai foreign key onDelete cascade)
            // Tapi jika butuh hapus file storage:
            $option->values->each(function ($value) {
                if ($value->image_path && Storage::disk('public')->exists($value->image_path)) {
                    Storage::disk('public')->delete($value->image_path);
                }
            });
        });

        return response()->json([
            'message' => 'Pilihan dihapus.',
            'promoted_option' => $isFirstOption ? $product->options()->with('values')->first() : null,
        ]);
    }
}