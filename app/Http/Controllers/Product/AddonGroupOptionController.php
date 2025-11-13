<?php

namespace App\Http\Controllers\Product;

use App\Models\Addon;
use App\Models\Product;
use App\Models\AddonGroup;
use Illuminate\Http\Request;
use App\Models\AddonGroupOption;
use App\Http\Controllers\Controller;

class AddonGroupOptionController extends Controller
{
    // Add addon to group
    public function store(Request $request, Product $product, AddonGroup $group)
    {
        $this->authorize('update', $product);

        if ((int) $group->product_id !== (int) $product->id) {
            return response()->json(['message' => 'Group tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'addon_id' => ['required', 'integer', 'exists:addons,id'],
            'addon_price' => ['nullable', 'numeric', 'min:0'],
            'addon_stock' => ['nullable', 'integer', 'min:0'],
        ]);

        // Validasi: addon harus milik merchant yang sama
        $addon = Addon::findOrFail($data['addon_id']);
        if ((int) $addon->merchant_id !== (int) $product->merchant_id) {
            return response()->json(['message' => 'Addon tidak ditemukan.'], 404);
        }

        // Cek duplikat
        if ($group->options()->where('addon_id', $addon->id)->exists()) {
            return response()->json(['message' => 'Addon sudah ada di group ini.'], 422);
        }

        $option = $group->options()->create([
            'addon_id' => $addon->id,
            'addon_price' => $data['addon_price'] ?? 0,
            'addon_stock' => $data['addon_stock'] ?? null, // null = unlimited
        ]);

        return response()->json($option->load('addon'), 201);
    }

    // Update addon option (price/stock)
    public function update(Request $request, Product $product, AddonGroup $group, AddonGroupOption $option)
    {
        $this->authorize('update', $product);

        if (
            (int) $group->product_id !== (int) $product->id ||
            (int) $option->addon_group_id !== (int) $group->id
        ) {
            return response()->json(['message' => 'Option tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'addon_price' => ['sometimes', 'numeric', 'min:0'],
            'addon_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $option->update($data);

        return response()->json($option->fresh('addon'));
    }

    // Remove addon from group
    public function destroy(Product $product, AddonGroup $group, AddonGroupOption $option)
    {
        $this->authorize('update', $product);

        if (
            (int) $group->product_id !== (int) $product->id ||
            (int) $option->addon_group_id !== (int) $group->id
        ) {
            return response()->json(['message' => 'Option tidak ditemukan.'], 404);
        }

        $option->delete();

        return response()->json(['message' => 'Addon dihapus dari group.']);
    }
}
