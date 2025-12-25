<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function merchantDashboard(Request $request, int $merchant)
    {
        $user = $request->user();

        // 🔐 Validasi kepemilikan merchant
        $merchantModel = Merchant::where('id', $merchant)
            ->where('user_id', $user->id)
            ->first();

        if (!$merchantModel) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke merchant ini'
            ], 403);
        }

        $merchantId = $merchantModel->id;

        /**
         * =========================
         * STAT PRODUK
         * =========================
         */
        $totalProducts = Product::where('merchant_id', $merchantId)->count();
        $published = Product::where('merchant_id', $merchantId)->published()->count();
        $draft = Product::where('merchant_id', $merchantId)->draft()->count();
        $archived = Product::where('merchant_id', $merchantId)->archived()->count();

        /**
         * =========================
         * STOK (VARIANT-BASED)
         * =========================
         */
        $lowStock = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.merchant_id', $merchantId)
            ->where('products.status', 'published')
            ->whereBetween('product_variants.stock', [1, 5])
            ->distinct('products.id')
            ->count('products.id');

        $outOfStock = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.merchant_id', $merchantId)
            ->where('products.status', 'published')
            ->where('product_variants.stock', 0)
            ->distinct('products.id')
            ->count('products.id');

        /**
         * =========================
         * CHART KATEGORI
         * =========================
         */
        /**
         * =========================
         * CHART KATEGORI (TOP 3 + LAINNYA)
         * =========================
         */
        $categoryStats = Category::whereHas('products', function ($q) use ($merchantId) {
            $q->where('merchant_id', $merchantId)
                ->where('status', 'published');
        })
            ->withCount([
                'products as total' => function ($q) use ($merchantId) {
                    $q->where('merchant_id', $merchantId)
                        ->where('status', 'published');
                }
            ])
            ->orderByDesc('total')
            ->get();


        $topCategories = $categoryStats->take(3);
        $otherTotal = $categoryStats->slice(3)->sum('total');

        $labels = $topCategories->pluck('name')->toArray();
        $data = $topCategories->pluck('total')->toArray();

        if ($otherTotal > 0) {
            $labels[] = 'Lainnya';
            $data[] = $otherTotal;
        }


        return response()->json([
            'merchant' => [
                'id' => $merchantModel->id,
                'name' => $merchantModel->name,
            ],
            'stats' => [
                'total' => $totalProducts,
                'published' => $published,
                'draft' => $draft,
                'archived' => $archived,
                'low_stock' => $lowStock,
                'out_of_stock' => $outOfStock,
            ],
            'charts' => [
                'status' => [
                    'labels' => ['Published', 'Draft', 'Archived'],
                    'data' => [$published, $draft, $archived],
                ],
                'category' => [
                    'labels' => $labels,
                    'datasets' => [
                        ['data' => $data]
                    ]
                ]
            ]
        ]);
    }
}

