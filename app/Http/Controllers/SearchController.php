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

            'sort' => ['nullable', 'in:latest,oldest,cheapest,expensive,nearest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],

            // Nearest sorting params (required when sort=nearest)
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_if:sort,nearest'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_if:sort,nearest'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:200'],
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
            ->whereIn('products.status', ['published'])
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

        $sort = $data['sort'] ?? 'latest';

        $hasCoords = isset($data['lat']) && isset($data['lng']);
        if ($hasCoords && $sort !== 'nearest') {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];

            $merchantMorphClass = (new Merchant())->getMorphClass();

            // Join merchant primary address (label=utama) for distance computation.
            // LEFT JOIN so items without coordinates still show up (distance_km=null).
            $primaryAddrIdSub = DB::table('addresses')
                ->selectRaw('addressable_id, MAX(id) as addr_id')
                ->where('addressable_type', $merchantMorphClass)
                ->where('label', 'utama')
                ->groupBy('addressable_id');

            $query
                ->leftJoinSub($primaryAddrIdSub, 'pa', function ($join) {
                    $join->on('pa.addressable_id', '=', 'products.merchant_id');
                })
                ->leftJoin('addresses as addr', 'addr.id', '=', 'pa.addr_id')
                ->selectRaw(
                    '(
                        CASE
                            WHEN addr.latitude IS NULL OR addr.longitude IS NULL THEN NULL
                            ELSE (
                                6371 * acos(
                                    cos(radians(?)) * cos(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                                    * cos(radians(CAST(addr.longitude AS DECIMAL(10,7))) - radians(?))
                                    + sin(radians(?)) * sin(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                                )
                            )
                        END
                    ) as distance_km',
                    [$lat, $lng, $lat]
                );
        }

        if ($sort === 'nearest') {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];

            $merchantMorphClass = (new Merchant())->getMorphClass();

            // Join merchant primary address (label=utama) for distance computation.
            // Use subquery to ensure 1 address row per merchant.
            $primaryAddrIdSub = DB::table('addresses')
                ->selectRaw('addressable_id, MAX(id) as addr_id')
                ->where('addressable_type', $merchantMorphClass)
                ->where('label', 'utama')
                ->groupBy('addressable_id');

            $query
                ->joinSub($primaryAddrIdSub, 'pa', function ($join) {
                    $join->on('pa.addressable_id', '=', 'products.merchant_id');
                })
                ->join('addresses as addr', 'addr.id', '=', 'pa.addr_id')
                ->whereNotNull('addr.latitude')
                ->whereNotNull('addr.longitude')
                ->selectRaw(
                    '(
                        6371 * acos(
                            cos(radians(?)) * cos(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                            * cos(radians(CAST(addr.longitude AS DECIMAL(10,7))) - radians(?))
                            + sin(radians(?)) * sin(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                        )
                    ) as distance_km',
                    [$lat, $lng, $lat]
                )
                ->orderBy('distance_km');

            if (isset($data['radius_km'])) {
                $query->having('distance_km', '<=', (float) $data['radius_km']);
            }
        } else {
            match ($sort) {
                'latest' => $query->orderByDesc('products.created_at'),
                'oldest' => $query->orderBy('products.created_at'),
                'cheapest' => $query->orderBy('min_price'),
                'expensive' => $query->orderByDesc('max_price'),
                default => null,
            };
        }

        $perPage = $data['per_page'] ?? 12;

        $result = $query->paginate($perPage);
        $items = collect($result->items())
            ->map(function ($product) {
                if ($product->coverImage) {
                    $product->cover_image = route(
                        'images.show',
                        ['image' => $product->coverImage->id]
                    );
                } else {
                    $product->cover_image = null;
                }

                unset($product->coverImage);
                return $product;
            })
            ->values();

        return response()->json([
            'data' => $items,
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

            'sort' => ['nullable', 'in:latest,oldest,most_products,nearest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'is_open' => ['nullable', 'boolean'],

            // Nearest sorting params (required when sort=nearest)
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_if:sort,nearest'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_if:sort,nearest'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:200'],
        ]);

        $query = Merchant::query()
            ->select([
                    'merchants.id',
                    'merchants.name',
                    'merchants.slug',
                    'merchants.segmentation_id',
                    'merchants.logo_path',
                    'merchants.operational_hours',
                    'merchants.status',
                    'merchants.created_at',
                ])
            ->approved()
            ->with([
                    'segmentation:id,name',
                    'primaryAddress:id,addressable_id,detail,village_id,district_id,city_id,province_id,latitude,longitude',
                    'primaryAddress.village:id,name',
                    'primaryAddress.district:id,name',
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

        $hasCoords = isset($data['lat']) && isset($data['lng']);
        if ($hasCoords && $sort !== 'nearest') {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];

            $merchantMorphClass = (new Merchant())->getMorphClass();

            // LEFT JOIN so merchants without coordinates still show up (distance_km=null).
            $primaryAddrIdSub = DB::table('addresses')
                ->selectRaw('addressable_id, MAX(id) as addr_id')
                ->where('addressable_type', $merchantMorphClass)
                ->where('label', 'utama')
                ->groupBy('addressable_id');

            $query
                ->leftJoinSub($primaryAddrIdSub, 'pa', function ($join) {
                    $join->on('pa.addressable_id', '=', 'merchants.id');
                })
                ->leftJoin('addresses as addr', 'addr.id', '=', 'pa.addr_id')
                ->selectRaw(
                    '(
                        CASE
                            WHEN addr.latitude IS NULL OR addr.longitude IS NULL THEN NULL
                            ELSE (
                                6371 * acos(
                                    cos(radians(?)) * cos(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                                    * cos(radians(CAST(addr.longitude AS DECIMAL(10,7))) - radians(?))
                                    + sin(radians(?)) * sin(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                                )
                            )
                        END
                    ) as distance_km',
                    [$lat, $lng, $lat]
                );
        }

        if ($sort === 'nearest') {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];

            $merchantMorphClass = (new Merchant())->getMorphClass();

            // Join merchant primary address (label=utama) for distance computation.
            // Use subquery to ensure 1 address row per merchant.
            $primaryAddrIdSub = DB::table('addresses')
                ->selectRaw('addressable_id, MAX(id) as addr_id')
                ->where('addressable_type', $merchantMorphClass)
                ->where('label', 'utama')
                ->groupBy('addressable_id');

            $query
                ->joinSub($primaryAddrIdSub, 'pa', function ($join) {
                    $join->on('pa.addressable_id', '=', 'merchants.id');
                })
                ->join('addresses as addr', 'addr.id', '=', 'pa.addr_id')
                ->whereNotNull('addr.latitude')
                ->whereNotNull('addr.longitude')
                ->selectRaw(
                    '(
                        6371 * acos(
                            cos(radians(?)) * cos(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                            * cos(radians(CAST(addr.longitude AS DECIMAL(10,7))) - radians(?))
                            + sin(radians(?)) * sin(radians(CAST(addr.latitude AS DECIMAL(10,7))))
                        )
                    ) as distance_km',
                    [$lat, $lng, $lat]
                )
                ->orderBy('distance_km');

            if (isset($data['radius_km'])) {
                $query->having('distance_km', '<=', (float) $data['radius_km']);
            }
        } else {
            match ($sort) {
                'latest' => $query->orderByDesc('merchants.created_at'),
                'oldest' => $query->orderBy('merchants.created_at'),
                'most_products' => $query
                    ->withCount(['products' => fn($q) => $q->where('status', 'published')])
                    ->orderByDesc('products_count'),
                default => null,
            };
        }

        $perPage = $data['per_page'] ?? 10;

        // If is_open is provided, filter by computed attribute is_open_now.
        // This can't be expressed easily in SQL (depends on timezone & current time),
        // so we filter in memory and paginate the filtered results.
        if (array_key_exists('is_open', $data)) {
            $desired = (bool) $data['is_open'];
            $all = $query->get();
            $filtered = $all->filter(fn(Merchant $m) => (bool) $m->is_open_now === $desired)->values();

            $page = (int) $request->input('page', 1);
            if ($page < 1) {
                $page = 1;
            }

            $pageItems = $filtered->forPage($page, $perPage)->values();
            $result = new \Illuminate\Pagination\LengthAwarePaginator(
                $pageItems,
                $filtered->count(),
                $perPage,
                $page,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        } else {
            $result = $query->paginate($perPage);
        }

        $items = collect($result->items())
            ->map(function (Merchant $merchant) {
                $payload = $merchant->toArray();

                $payload['logo_url'] = !empty($merchant->logo_path)
                    ? route('merchant_profile_pictures.show', ['merchant' => $merchant->id])
                    : null;

                unset($payload['logo_path']);
                unset($payload['operational_hours']);

                return $payload;
            })
            ->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'total' => $result->total(),
            ],
        ]);
    }

}
