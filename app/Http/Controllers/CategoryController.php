<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Get all level 1 categories (parent categories)
     */
    public function getLevel1Categories(): JsonResponse
    {
        try {
            $categories = Category::whereNull('parent_id')
                ->select('id', 'name', 'slug', 'image_path')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($category) {
                    return [
                        'value' => $category->id,
                        'label' => $category->name,
                        'slug' => $category->slug,
                        'image_path' => $category->image_path,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $categories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sub-categories by parent ID
     */
    public function getSubCategories(Request $request, $parentId): JsonResponse
    {
        try {
            $parent = Category::find($parentId);

            if (!$parent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent category not found',
                ], 404);
            }

            $subCategories = Category::where('parent_id', $parentId)
                ->select('id', 'name', 'slug', 'image_path')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($category) {
                    return [
                        'value' => $category->id,
                        'label' => $category->name,
                        'slug' => $category->slug,
                        'image_path' => $category->image_path,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Sub-categories retrieved successfully',
                'data' => $subCategories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sub-categories',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get products by category slug
     */
    public function getProductsByCategory(Request $request, string $slug): JsonResponse
    {
        try {
            $category = Category::where('slug', $slug)->firstOrFail();

            $products = $category->products()
                ->where('status', 'published')
                ->whereHas('merchant', fn($q) => $q->where('status', 'approved'))
                ->with(['merchant:id,name,slug', 'coverImage', 'variants'])
                ->paginate($request->input('per_page', 20));

            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'parent' => $category->parent,
                ],
                'data' => $products,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
