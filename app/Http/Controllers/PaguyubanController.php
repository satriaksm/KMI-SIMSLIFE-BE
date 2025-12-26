<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Paguyuban;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PaguyubanController extends Controller
{
    public function index(Request $request)
    {
        $query = Paguyuban::withCount('merchants');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $paguyubans = $query->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($paguyubans);
    }

    public function show($id)
    {
        $paguyuban = Paguyuban::with('merchants')
            ->withCount('merchants')
            ->findOrFail($id);

        return response()->json(['data' => $paguyuban]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'contact_info' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')
                ->store('paguyubans', 'public');
        }

        $paguyuban = Paguyuban::create($data);

        return response()->json([
            'message' => 'Paguyuban created successfully',
            'data' => $paguyuban,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $paguyuban = Paguyuban::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'contact_info' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('image')) {
            // Delete old image
            if ($paguyuban->image_path) {
                Storage::disk('public')->delete($paguyuban->image_path);
            }
            $data['image_path'] = $request->file('image')
                ->store('paguyubans', 'public');
        }

        $paguyuban->update($data);

        return response()->json([
            'message' => 'Paguyuban updated successfully',
            'data' => $paguyuban->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $paguyuban = Paguyuban::findOrFail($id);

        // Check if has merchants
        if ($paguyuban->merchants()->exists()) {
            return response()->json([
                'message' => 'Cannot delete paguyuban with existing merchants',
            ], 422);
        }

        // Delete image
        if ($paguyuban->image_path) {
            Storage::disk('public')->delete($paguyuban->image_path);
        }

        $paguyuban->delete();

        return response()->json([
            'message' => 'Paguyuban deleted successfully',
        ]);
    }

    public function toggleStatus($id)
    {
        // Add is_active column to paguyubans table first
        $paguyuban = Paguyuban::findOrFail($id);
        
        $newStatus = $paguyuban->is_active ? false : true;
        $paguyuban->update(['is_active' => $newStatus]);

        return response()->json([
            'message' => 'Status updated successfully',
            'data' => $paguyuban->fresh(),
        ]);
    }
}