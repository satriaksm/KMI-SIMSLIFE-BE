<?php

namespace App\Http\Controllers\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class ProductVariantController extends Controller
{
    private const MAX_VARIANTS = 50;

    // List variants + option values (dengan display_image accessor)
    public function index(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        return response()->json(
            $product->variants()->with(['optionValues.option'])->get()
        );
    }

    // Create variant (dengan validasi kombinasi)
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:product_variants,sku'],
            'option_value_ids' => ['required', 'array', 'min:1'],
            'option_value_ids.*' => ['integer', 'exists:product_option_values,id'],
        ]);

        // Validasi: maksimal 50 variant per product
        if ($product->variants()->count() >= self::MAX_VARIANTS) {
            return response()->json([
                'message' => 'Produk tidak dapat memiliki lebih dari ' . self::MAX_VARIANTS . ' varian.',
            ], 422);
        }

        // Validasi: semua option_value_ids harus dari product ini
        $validIds = $product->options()->with('values')->get()
            ->pluck('values')->flatten()->pluck('id')->toArray();

        $diff = array_diff($data['option_value_ids'], $validIds);
        if (!empty($diff)) {
            return response()->json(['message' => 'Ids Value pilihan tidak valid.'], 422);
        }

        // Validasi: kombinasi option_value_ids harus unique (tidak ada duplikat variant)
        if ($this->variantCombinationExists($product, $data['option_value_ids'])) {
            return response()->json([
                'message' => 'Kombinasi varian ini sudah ada.',
            ], 422);
        }

        $variant = $product->variants()->create([
            'stock' => $data['stock'],
            'price' => $data['price'],
            'sku' => $data['sku'] ?? null,
        ]);

        // Attach option values
        $variant->optionValues()->attach($data['option_value_ids']);

        return response()->json($variant->load('optionValues.option'), 201);
    }

    // Update variant (stock, price, sku)
    public function update(Request $request, Product $product, ProductVariant $variant)
    {
        $this->authorize('update', $product);

        if ((int) $variant->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Variant tidak ditemukan untuk produk ini.',
            ], 404);
        }

        $data = $request->validate([
            'stock' => ['sometimes', 'integer', 'min:0'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:product_variants,sku,' . $variant->id],
        ]);

        $variant->update($data);

        return response()->json($variant->fresh('optionValues.option'));
    }

    // Delete variant
    public function destroy(Request $request, Product $product, ProductVariant $variant)
    {
        $this->authorize('update', $product);

        if ((int) $variant->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Variant tidak ditemukan untuk produk ini.',
            ], 404);
        }

        $variant->delete(); // cascade detach pivot

        return response()->json(['message' => 'Variant dihapus.']);
    }

    /**
     * Cek apakah kombinasi option_value_ids sudah ada di product ini
     */
    private function variantCombinationExists(Product $product, array $optionValueIds): bool
    {
        $count = count($optionValueIds);
        sort($optionValueIds);

        // Cari variant yang punya EXACT kombinasi (jumlah sama + ids sama)
        $variants = $product->variants()
            ->select('product_variants.id')
            ->join('product_variant_option_values as pvov', 'product_variants.id', '=', 'pvov.product_variant_id')
            ->whereIn('pvov.product_option_value_id', $optionValueIds)
            ->groupBy('product_variants.id')
            ->havingRaw('COUNT(DISTINCT pvov.product_option_value_id) = ?', [$count])
            ->get();

        // Double check di memory (fallback untuk edge case)
        foreach ($variants as $variant) {
            $existingIds = DB::table('product_variant_option_values')
                ->where('product_variant_id', $variant->id)
                ->pluck('product_option_value_id')
                ->toArray();

            sort($existingIds);

            if ($existingIds === $optionValueIds) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update option values dari variant yang sudah ada
     * Untuk menambahkan/mengubah kombinasi setelah ada option baru
     */
    public function updateOptionValues(Request $request, Product $product, ProductVariant $variant)
    {
        $this->authorize('update', $product);

        if ((int) $variant->product_id !== (int) $product->id) {
            return response()->json([
                'message' => 'Variant tidak ditemukan untuk produk ini.',
            ], 404);
        }

        $data = $request->validate([
            'option_value_ids' => ['required', 'array', 'min:1'],
            'option_value_ids.*' => ['integer', 'exists:product_option_values,id'],
        ]);

        // Validasi: semua option_value_ids harus dari product ini
        $validIds = $product->options()->with('values')->get()
            ->pluck('values')->flatten()->pluck('id')->toArray();

        $diff = array_diff($data['option_value_ids'], $validIds);
        if (!empty($diff)) {
            return response()->json(['message' => 'Ids Value pilihan tidak valid.'], 422);
        }

        // Validasi: jumlah value harus sama dengan jumlah option product
        $optionCount = $product->options()->count();
        if (count($data['option_value_ids']) !== $optionCount) {
            return response()->json([
                'message' => "Harus memilih {$optionCount} nilai (sesuai jumlah pilihan produk).",
            ], 422);
        }

        // Validasi: kombinasi baru tidak boleh sama dengan variant lain (kecuali diri sendiri)
        if ($this->variantCombinationExistsExcept($product, $data['option_value_ids'], $variant->id)) {
            return response()->json([
                'message' => 'Kombinasi varian ini sudah digunakan oleh varian lain.',
            ], 422);
        }

        // Sync option values (replace existing)
        $variant->optionValues()->sync($data['option_value_ids']);

        return response()->json($variant->fresh('optionValues.option'));
    }

    /**
     * Cek duplikat kombinasi tapi exclude variant tertentu
     */
    private function variantCombinationExistsExcept(Product $product, array $optionValueIds, int $excludeVariantId): bool
    {
        $count = count($optionValueIds);
        sort($optionValueIds);

        $variants = $product->variants()
            ->where('product_variants.id', '!=', $excludeVariantId) // ✅ qualify dengan table name
            ->select('product_variants.id')
            ->join('product_variant_option_values as pvov', 'product_variants.id', '=', 'pvov.product_variant_id')
            ->whereIn('pvov.product_option_value_id', $optionValueIds)
            ->groupBy('product_variants.id')
            ->havingRaw('COUNT(DISTINCT pvov.product_option_value_id) = ?', [$count])
            ->get();

        foreach ($variants as $variant) {
            $existingIds = DB::table('product_variant_option_values')
                ->where('product_variant_id', $variant->id)
                ->pluck('product_option_value_id')
                ->toArray();

            sort($existingIds);

            if ($existingIds === $optionValueIds) {
                return true;
            }
        }

        return false;
    }
}
