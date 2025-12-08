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
     * Get all categories with their children (tree structure)
     */
    public function getCategoriesTree(): JsonResponse
    {
        try {
            $categories = Category::whereNull('parent_id')
                ->with('children')
                ->select('id', 'name', 'slug', 'image_path')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($category) {
                    return [
                        'value' => $category->id,
                        'label' => $category->name,
                        'slug' => $category->slug,
                        'image_path' => $category->image_path,
                        'children' => $category->children->map(function ($child) {
                            return [
                                'value' => $child->id,
                                'label' => $child->name,
                                'slug' => $child->slug,
                                'image_path' => $child->image_path,
                            ];
                        }),
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Categories tree retrieved successfully',
                'data' => $categories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories tree',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search categories by name
     */
    public function searchCategories(Request $request): JsonResponse
    {
        try {
            $query = $request->input('q', '');

            $categories = Category::where('name', 'like', "%{$query}%")
                ->select('id', 'parent_id', 'name', 'slug', 'image_path')
                ->orderBy('name', 'asc')
                ->limit(20)
                ->get()
                ->map(function ($category) {
                    return [
                        'value' => $category->id,
                        'label' => $category->name,
                        'slug' => $category->slug,
                        'parent_id' => $category->parent_id,
                        'image_path' => $category->image_path,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Categories found',
                'data' => $categories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}