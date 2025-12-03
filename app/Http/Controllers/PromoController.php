<?php

namespace App\Http\Controllers;

use App\Models\Promo;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function index()
    {
        return Promo::where('is_active', true)->get();
    }

    public function show($id)
    {
        $promo = Promo::findOrFail($id);
        return response()->json($promo);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:promos',
            'title' => 'required|string|max:255',
            'desc' => 'nullable|string',
            'type' => 'required|in:percent,flat',
            'value' => 'required|integer',
            'is_active' => 'boolean',
        ]);

        $promo = Promo::create($data);
        return response()->json($promo, 201);
    }

    public function destroy($id)
    {
        Promo::findOrFail($id)->delete();
        return response()->json(['message' => 'Promo dihapus']);
    }
}
