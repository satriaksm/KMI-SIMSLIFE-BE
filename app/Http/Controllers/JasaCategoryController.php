<?php

namespace App\Http\Controllers;

use App\Models\JasaCategory;
use App\Models\JasaSubcategory;
use Illuminate\Http\Request;

class JasaCategoryController extends Controller
{
    // GET /api/jasa-categories (public)
    public function index(Request $request)
    {
        $query = JasaCategory::query();

        if ($request->boolean('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $categories = $query->orderBy('name')->get();
        return response()->json($categories);
    }

    // GET /api/jasa-categories/{id} (public)
    public function show($id)
    {
        $category = JasaCategory::findOrFail($id);
        return response()->json($category);
    }

    // GET /api/jasa-categories/{id}/subcategories (public)
    public function getSubcategories($id)
    {
        $category = JasaCategory::findOrFail($id);
        $subcategories = $category->subcategories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($subcategories);
    }

    // POST /api/jasa-categories (admin)
    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'name' => 'required|string|unique:jasa_categories,name|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $category = JasaCategory::create($validated);

        return response()->json([
            'message' => 'Kategori berhasil dibuat',
            'data' => $category
        ], 201);
    }

    // PUT /api/jasa-categories/{id} (admin)
    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $category = JasaCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|unique:jasa_categories,name,' . $id . '|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Kategori berhasil diperbarui',
            'data' => $category
        ]);
    }

    // DELETE /api/jasa-categories/{id} (admin)
    public function destroy($id)
    {
        $this->authorizeAdmin();

        $category = JasaCategory::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Kategori berhasil dihapus']);
    }

    private function authorizeAdmin()
    {
        $user = auth()->user();
        if (!$user || !$user->roles()->whereRaw('LOWER(name) = ?', ['admin'])->exists()) {
            abort(403, 'Akses ditolak. Hanya admin.');
        }
    }
}

