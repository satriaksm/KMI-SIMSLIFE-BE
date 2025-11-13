<?php

namespace App\Http\Controllers\Product;

use App\Models\Addon;
use App\Models\Merchant;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AddonController extends Controller
{
    // List addons for merchant
    public function index(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
        ]);

        $merchant = Merchant::findOrFail($data['merchant_id']);
        $this->authorize('update', $merchant); // hanya owner

        $addons = Addon::where('merchant_id', $merchant->id)
            ->orderBy('addon_name')
            ->get();

        return response()->json($addons);
    }

    // Create addon
    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'addon_name' => ['required', 'string', 'max:100'],
        ]);

        $merchant = Merchant::findOrFail($data['merchant_id']);
        $this->authorize('update', $merchant);

        // Cek duplikat addon_name per merchant
        if (
            Addon::where('merchant_id', $merchant->id)
                ->where('addon_name', $data['addon_name'])
                ->exists()
        ) {
            return response()->json(['message' => 'Nama addon sudah ada.'], 422);
        }

        $addon = Addon::create($data);

        return response()->json($addon, 201);
    }

    // Update addon
    public function update(Request $request, Addon $addon)
    {
        $this->authorize('update', $addon->merchant);

        $data = $request->validate([
            'addon_name' => ['sometimes', 'required', 'string', 'max:100'],
        ]);

        // Cek duplikat (exclude diri sendiri)
        if (
            isset($data['addon_name']) &&
            Addon::where('merchant_id', $addon->merchant_id)
                ->where('addon_name', $data['addon_name'])
                ->where('id', '!=', $addon->id)
                ->exists()
        ) {
            return response()->json(['message' => 'Nama addon sudah ada.'], 422);
        }

        $addon->update($data);

        return response()->json($addon);
    }

    // Delete addon
    public function destroy(Addon $addon)
    {
        $this->authorize('update', $addon->merchant);

        $addon->delete();

        return response()->json(['message' => 'Addon dihapus.']);
    }
}
