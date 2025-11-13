<?php

namespace App\Http\Controllers\Product;

use App\Models\Product;
use App\Models\AddonGroup;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AddonGroupController extends Controller
{
    // List addon groups for product
    public function index(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $groups = $product->addonGroups()
            ->with('options.addon')
            ->get();

        return response()->json($groups);
    }

    // Create addon group
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'addon_group_name' => ['required', 'string', 'max:100'],
            'selection_type' => ['required', 'in:single,multiple'],
            'min_selection' => ['nullable', 'integer', 'min:0', 'max:255'],
            'max_selection' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        // Validasi: min_selection tidak boleh > max_selection
        if (
            isset($data['min_selection']) &&
            isset($data['max_selection']) &&
            $data['min_selection'] > $data['max_selection']
        ) {
            return response()->json([
                'message' => 'Jumlah minimum tidak boleh lebih besar dari maksimum.',
            ], 422);
        }

        // Validasi: single selection harus max_selection=1
        if ($data['selection_type'] === 'single' && ($data['max_selection'] ?? 1) > 1) {
            return response()->json([
                'message' => 'Tipe single hanya boleh maksimal 1 pilihan.',
            ], 422);
        }

        $group = $product->addonGroups()->create([
            'addon_group_name' => $data['addon_group_name'],
            'selection_type' => $data['selection_type'],
            'min_selection' => $data['min_selection'] ?? 0,
            'max_selection' => $data['selection_type'] === 'single' ? 1 : ($data['max_selection'] ?? null),
        ]);

        return response()->json($group, 201);
    }

    // Update addon group
    public function update(Request $request, Product $product, AddonGroup $group)
    {
        $this->authorize('update', $product);

        if ((int) $group->product_id !== (int) $product->id) {
            return response()->json(['message' => 'Group tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'addon_group_name' => ['sometimes', 'required', 'string', 'max:100'],
            'selection_type' => ['sometimes', 'required', 'in:single,multiple'],
            'min_selection' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'max_selection' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
        ]);

        // Validasi min/max
        $minSel = $data['min_selection'] ?? $group->min_selection;
        $maxSel = $data['max_selection'] ?? $group->max_selection;

        if ($minSel && $maxSel && $minSel > $maxSel) {
            return response()->json([
                'message' => 'Jumlah minimum tidak boleh lebih besar dari maksimum.',
            ], 422);
        }

        // Validasi single selection
        $selType = $data['selection_type'] ?? $group->selection_type;
        if ($selType === 'single' && $maxSel > 1) {
            return response()->json([
                'message' => 'Tipe single hanya boleh maksimal 1 pilihan.',
            ], 422);
        }

        $group->update($data);

        return response()->json($group->fresh('options.addon'));
    }

    // Delete addon group
    public function destroy(Product $product, AddonGroup $group)
    {
        $this->authorize('update', $product);

        if ((int) $group->product_id !== (int) $product->id) {
            return response()->json(['message' => 'Group tidak ditemukan.'], 404);
        }

        $group->delete(); // cascade delete options

        return response()->json(['message' => 'Group addon dihapus.']);
    }
}
