<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Merchant;
use Illuminate\Http\Request;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function searchProducts(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],

            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],

            'categories' => ['nullable', 'array'],
            'categories.*' => ['string'],

            'segments' => ['nullable', 'array'],
            'segments.*' => ['string', 'in:UMKM Toko,UMKM Kuliner,UMKM Jasa'],

            'sort' => ['nullable', 'in:latest,oldest,cheapest,expensive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()
            ->select([
                'products.id',
                'products.merchant_id',
                'products.name',
                'products.slug',
                'products.created_at',
            ])
            ->with([
                'merchant:id,name',
                'merchant.segmentation:id,name',
                'coverImage:id,imageable_id,imageable_type,image_path',
            ])
            ->whereIn('status', ['published'])
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved');
            })
            ->whereHas('variants', function ($q) {
                $q->where('stock', '>', 0);
            });

        if (!empty($data['q'])) {
            $query->where(function ($q) use ($data) {
                $q->where('products.name', 'like', "%{$data['q']}%")
                    ->orWhereHas(
                        'merchant',
                        fn($m) =>
                        $m->where('name', 'like', "%{$data['q']}%")
                    );
            });
        }

        if (!empty($data['categories'])) {
            $query->whereHas('categories', function ($q) use ($data) {
                $q->whereIn('slug', $data['categories']);
            });
        }

        if (!empty($data['segments'])) {
            $query->whereHas('merchant.segmentation', function ($q) use ($data) {
                $q->whereIn('name', $data['segments']);
            });
        }

        $query->addSelect([
            'min_price' => ProductVariant::selectRaw('MIN(price)')
                ->whereColumn('product_id', 'products.id'),

            'max_price' => ProductVariant::selectRaw('MAX(price)')
                ->whereColumn('product_id', 'products.id'),
        ]);

        if (isset($data['min_price'])) {
            $query->whereRaw(
                '(select MIN(price) from product_variants where product_id = products.id) >= ?',
                [$data['min_price']]
            );
        }

        if (isset($data['max_price'])) {
            $query->whereRaw(
                '(select MAX(price) from product_variants where product_id = products.id) <= ?',
                [$data['max_price']]
            );
        }


        $sort = $data['sort'] ?? 'latest';

        match ($sort) {
            'latest' => $query->orderByDesc('products.created_at'),
            'oldest' => $query->orderBy('products.created_at'),
            'cheapest' => $query->orderBy('min_price'),
            'expensive' => $query->orderByDesc('max_price'),
            default => null,
        };

        $perPage = $data['per_page'] ?? 12;

        $result = $query->paginate($perPage);

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'total' => $result->total(),
            ],
        ]);
    }

    public function searchMerchants(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],

            'segments' => ['nullable', 'array'],
            'segments.*' => ['string'],

            'categories' => ['nullable', 'array'],
            'categories.*' => ['string'],

            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],

            'sort' => ['nullable', 'in:latest,oldest,most_products'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Merchant::query()
            ->select([
                'merchants.id',
                'merchants.name',
                'merchants.slug',
                'merchants.segmentation_id',
                'merchants.status',
                'merchants.created_at',
            ])
            ->approved()
            ->with([
                'segmentation:id,name',
                'primaryAddress:id,addressable_id,detail,city_id,province_id,latitude,longitude',
                'primaryAddress.city:id,name',
                'primaryAddress.province:id,name',
            ])
            ->withCount([
                'products as products_count' => function ($q) {
                    $q->where('status', 'published')
                        ->whereHas('variants', function ($v) {
                            $v->where('stock', '>', 0);
                        });
                }
            ]);


        if (!empty($data['q'])) {
            $query->where('merchants.name', 'like', "%{$data['q']}%");
        }

        if (!empty($data['segments'])) {
            $query->whereHas('segmentation', function ($q) use ($data) {
                $q->whereIn('name', $data['segments']);
            });
        }
        $sort = $data['sort'] ?? 'latest';

        match ($sort) {
            'latest' => $query->orderByDesc('merchants.created_at'),
            'oldest' => $query->orderBy('merchants.created_at'),
            'most_products' => $query
                ->withCount(['products' => fn($q) => $q->where('status', 'published')])
                ->orderByDesc('products_count'),
            default => null,
        };

        $perPage = $data['per_page'] ?? 10;
        $result = $query->paginate($perPage);

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'total' => $result->total(),
            ],
        ]);
    }

}
