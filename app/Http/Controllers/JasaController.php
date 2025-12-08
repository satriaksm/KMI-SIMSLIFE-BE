<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use Illuminate\Http\Request;

class JasaController extends Controller
{
    /**
     * GET /api/jasa
     * Ambil semua jasa beserta paketnya
     */
    public function index()
    {
        $jasas = Jasa::with('packages')->orderBy('id', 'desc')->get();

        // Jika database kosong, kirim pesan kosong agar frontend tidak error
        if ($jasas->isEmpty()) {
            return response()->json(['message' => 'Belum ada data jasa.'], 200);
        }

        return response()->json($jasas);
    }

    /**
     * GET /api/jasa/{id}
     * Ambil detail 1 jasa berdasarkan ID
     */
    public function show($id)
    {
        $jasa = Jasa::with('packages')->find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        return response()->json($jasa);
    }

    /**
     * POST /api/jasa
     * Tambah data jasa baru (admin input)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'price' => 'required|integer|min:0',
            'image' => 'nullable|string|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'distance_km' => 'nullable|numeric|min:0',
            'duration_hours' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $jasa = Jasa::create($validated);

        return response()->json([
            'message' => 'Data jasa berhasil ditambahkan',
            'data' => $jasa
        ], 201);
    }

    /**
     * PUT /api/jasa/{id}
     * Update data jasa (admin edit)
     */
    public function update(Request $request, $id)
    {
        $jasa = Jasa::find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'price' => 'sometimes|required|integer|min:0',
            'image' => 'nullable|string|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'distance_km' => 'nullable|numeric|min:0',
            'duration_hours' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $jasa->update($validated);

        return response()->json([
            'message' => 'Data jasa berhasil diperbarui',
            'data' => $jasa
        ]);
    }

    /**
     * DELETE /api/jasa/{id}
     * Hapus data jasa (admin)
     */
    public function destroy($id)
    {
        $jasa = Jasa::find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        $jasa->delete();

        return response()->json(['message' => 'Data jasa berhasil dihapus']);
    }
}
