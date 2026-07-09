<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use App\Models\JasaOrderItem;
use App\Models\Merchant;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;

class JasaController extends Controller
{
    /**
     * Normalize price value: convert null/empty to 0, ensure integer
     */
    protected function normalizePrice(mixed $value): int
    {
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return 0;
        }
        return (int) $value;
    }

    protected function attachCoverImg(Jasa $jasa, bool $isPublic): void
    {
        if (!$jasa->relationLoaded('images')) {
            $jasa->load('images');
        }

        $images = $jasa->images ?? collect();
        $cover = $images->firstWhere('is_cover', true) ?? $images->first();

        if (!$cover) {
            $jasa->setAttribute('cover_img', null);
            // image_url handled by getImageUrlAttribute() accessor
            return;
        }

        // Always use public URL via asset() + /storage/ — no signed URL complexity
        // asset() respects APP_URL in production
        $imageUrl = asset('storage/' . $cover->image_path);

        // Also add the Image model ID so FE can call /api/images/{id}
        $srcUrl = route('images.show', ['image' => $cover->id]);

        $jasa->setAttribute('image', $imageUrl);
        $jasa->setAttribute('cover_img', [
            'id' => $cover->id,
            'path' => $cover->image_path,
            'url' => $imageUrl,
            'src_url' => $srcUrl,
            'image_url' => $imageUrl,
        ]);
    }

    protected function attachCategoryAliases(Jasa $jasa): void
    {
        if (!$jasa->relationLoaded('categories')) {
            $jasa->load('categories');
        }

        $categories = $jasa->categories ?? collect();

        $mainCategory = $categories->firstWhere('parent_id', null) ?? $categories->first();
        $subCategory = null;

        if ($mainCategory) {
            $subCategory = $categories->firstWhere('parent_id', $mainCategory->id);
        }

        if (!$subCategory) {
            $subCategory = $categories->firstWhere('parent_id', '!=', null);
        }

        if ($mainCategory && $subCategory && $mainCategory->id === $subCategory->id) {
            $subCategory = null;
        }

        $jasa->setAttribute('category', $mainCategory);
        $jasa->setAttribute('subcategory', $subCategory);
        $jasa->setAttribute('jasa_category_id', $mainCategory?->id);
        $jasa->setAttribute('jasa_subcategory_id', $subCategory?->id);
    }

    protected function syncJasaCategoriesFromRequest(Jasa $jasa, Request $request): void
    {
        $categoryIds = [];

        if ($request->filled('jasa_category_id')) {
            $categoryIds[] = (int) $request->input('jasa_category_id');
        }

        if ($request->filled('jasa_subcategory_id')) {
            $categoryIds[] = (int) $request->input('jasa_subcategory_id');
        }

        $categoryIds = array_values(array_unique(array_filter($categoryIds)));

        if (count($categoryIds) > 0) {
            $jasa->categories()->sync($categoryIds);
        }
    }

    /**
     * OWNER / ADMIN: daftar jasa (opsional filter merchantId).
     * Endpoint: GET /api/jasa
     */
    public function index(Request $request)
    {
        $query = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province:id,name',
            'merchant.primaryAddress.city:id,name',
            'merchant.primaryAddress.district:id,name',
            'merchant.primaryAddress.village:id,name',
            'images',
        ])
            // Tampilkan jasa lama di atas, yang baru di urutan terakhir
            ->orderBy('id', 'asc');

        // Optional filter by merchantId untuk tampilan owner
        if ($request->filled('merchantId')) {
            $query->where('merchant_id', $request->input('merchantId'));
        }

        $jasas = $query->get();

        $jasas->each(function ($jasa) {
            $this->attachCategoryAliases($jasa);

            // Normalize images for frontend — always use public URLs
            if ($jasa->images && $jasa->images->count() > 0) {
                $isPublic = in_array($jasa->status, ['published', 'active', 'archived'], true)
                    || ($jasa->status === null && (bool) $jasa->is_active);

                $jasa->images->transform(function ($image) use ($isPublic) {
                    $image->path = $image->image_path;
                    // Always use public URL (ImageController handles access control)
                    $publicUrl = route('images.show', ['image' => $image->id]);
                    $image->url = $publicUrl;
                    $image->src_url = $publicUrl;
                    $image->image_url = asset('storage/' . $image->image_path);

                    $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                    return $image;
                });

                $this->attachCoverImg($jasa, $isPublic);
            }
        });

        // Selalu kembalikan array (termasuk [] jika kosong) agar frontend konsisten
        return response()->json($jasas);
    }

    public function show($id)
    {
        $jasa = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province:id,name',
            'merchant.primaryAddress.city:id,name',
            'merchant.primaryAddress.district:id,name',
            'merchant.primaryAddress.village:id,name',
            'images',
        ])->find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        // Tambahkan alias field untuk kompatibilitas FE baru (Create/Edit Jasa)
        // FE menggunakan jasa_category_id & jasa_subcategory_id
        $this->attachCategoryAliases($jasa);

        // Normalisasi struktur images untuk FE (path, is_cover, display_order) — always public URLs
        if ($jasa->images) {
            $isPublic = in_array($jasa->status, ['published', 'active', 'archived'], true)
                || ($jasa->status === null && (bool) $jasa->is_active);

            $jasa->images->transform(function ($image) use ($isPublic) {
                $image->path = $image->image_path;
                // Always use public URL (ImageController handles access control)
                $publicUrl = route('images.show', ['image' => $image->id]);
                $image->url = $publicUrl;
                $image->src_url = $publicUrl;
                $image->image_url = asset('storage/' . $image->image_path);

                $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                return $image;
            });

            $this->attachCoverImg($jasa, $isPublic);
        }

        return response()->json($jasa);
    }

    /**
     * PUBLIC LIST: GET /api/public/jasas
     * Daftar jasa yang dapat dilihat customer.
     */
    public function publicIndex(Request $request)
    {
        // Params filter (dibuat mirip dengan public products)
        // - Tetap backward compatible: jika tidak kirim per_page/page => response tetap array seperti sebelumnya
        // - Merchant filter: dukung merchant_id (baru) dan merchantId (lama)
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'merchantId' => ['nullable', 'integer', 'exists:merchants,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],

            // filter by segmentation name (konsisten dengan ProductController::publicIndex)
            'segments' => ['nullable', 'array'],
            'segments.*' => ['in:UMKM Toko,UMKM Kuliner,UMKM Jasa'],

            // price range (berdasarkan harga efektif jasa)
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],

            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc,name_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province:id,name',
            'merchant.primaryAddress.city:id,name',
            'merchant.primaryAddress.district:id,name',
            'merchant.primaryAddress.village:id,name',
            'images',
        ])
            // Hanya tampilkan jasa yang aktif/dipublish ke customer
            ->where(function ($q) {
                // Kompatibel dengan dua skema status: status ('published' / 'active') atau flag is_active
                $q->whereIn('status', ['published', 'active'])
                    ->orWhere(function ($sub) {
                        $sub->whereNull('status')->where('is_active', true);
                    });
            });

        // Filter by merchant
        $merchantId = $data['merchant_id'] ?? $data['merchantId'] ?? null;
        if (!empty($merchantId)) {
            $query->where('merchant_id', (int) $merchantId);
        }

        // Filter by segmentation name
        if (!empty($data['segments'])) {
            $query->whereHas('merchant.segmentation', function ($q) use ($data) {
                $q->whereIn('name', $data['segments']);
            });
        }

        // Filter by category (via pivot categorizables)
        if (!empty($data['category_id'])) {
            $categoryId = (int) $data['category_id'];
            $query->whereHas('categories', fn($q) => $q->where('categories.id', $categoryId));
        }

        // Search
        if (!empty($data['q'])) {
            $q = $data['q'];
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        // Price range
        if (array_key_exists('min_price', $data) || array_key_exists('max_price', $data)) {
            // Harga efektif jasa: fixed_price > base_price > price (legacy)
            $priceExpr = "COALESCE(NULLIF(fixed_price, 0), NULLIF(base_price, 0), NULLIF(price, 0), 0)";

            if (isset($data['min_price'])) {
                $query->whereRaw("{$priceExpr} >= ?", [(float) $data['min_price']]);
            }
            if (isset($data['max_price'])) {
                $query->whereRaw("{$priceExpr} <= ?", [(float) $data['max_price']]);
            }
        }

        // Sorting
        $sort = $data['sort'] ?? 'newest';
        switch ($sort) {
            case 'price_asc':
                $query->orderByRaw("COALESCE(NULLIF(fixed_price, 0), NULLIF(base_price, 0), NULLIF(price, 0), 0) asc")
                    ->orderByDesc('id');
                break;
            case 'price_desc':
                $query->orderByRaw("COALESCE(NULLIF(fixed_price, 0), NULLIF(base_price, 0), NULLIF(price, 0), 0) desc")
                    ->orderByDesc('id');
                break;
            case 'name_asc':
                $query->orderBy('title', 'asc')->orderByDesc('id');
                break;
            case 'name_desc':
                $query->orderBy('title', 'desc')->orderByDesc('id');
                break;
            case 'newest':
            default:
                $query->orderByDesc('id');
                break;
        }

        $transform = function (Jasa $jasa) {
            $this->attachCategoryAliases($jasa);

            if ($jasa->images) {
                $jasa->images->transform(function ($image) {
                    $image->path = $image->image_path;
                    $image->url = route('images.show', ['image' => $image->id]);
                    $image->src_url = $image->url;
                    $image->image_url = asset('storage/' . $image->image_path);

                    $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                    return $image;
                });

                // public endpoint => always public URL
                $this->attachCoverImg($jasa, true);
            }

            return $jasa;
        };

        $wantsPagination = isset($data['per_page']) || isset($data['page']);
        if ($wantsPagination) {
            $perPage = $data['per_page'] ?? 20;
            return response()->json(
                $query->paginate($perPage)->through($transform)
            );
        }

        $jasas = $query->get()->each($transform);
        return response()->json($jasas);
    }

    /**
     * PUBLIC: GET /api/public/merchants/{merchantSlug}/jasas
     * Daftar jasa (published/active only) untuk merchant tertentu.
     */
    public function publicByMerchant(Request $request, string $merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)->firstOrFail();

        $data = $request->validate([
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province:id,name',
            'merchant.primaryAddress.city:id,name',
            'merchant.primaryAddress.district:id,name',
            'merchant.primaryAddress.village:id,name',
            'images',
        ])
            ->where('merchant_id', $merchant->id)
            ->where(function ($q) {
                $q->whereIn('status', ['published', 'active'])
                    ->orWhere(function ($sub) {
                        $sub->whereNull('status')->where('is_active', true);
                    });
            });

        // Add a computed price field for sorting: coalesce fixed_price, then base_price, then 0
        $query->selectRaw('jasas.*, COALESCE(NULLIF(fixed_price, 0), NULLIF(base_price, 0), 0) as sort_price');

        switch ($data['sort'] ?? 'newest') {
            case 'price_asc':
                $query->orderBy('sort_price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('sort_price', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('title', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('id', 'desc');
                break;
        }

        $perPage = $data['per_page'] ?? 20;
        $jasas = $query->paginate($perPage);

        $jasas->getCollection()->each(function ($jasa) {
            $this->attachCategoryAliases($jasa);

            if ($jasa->images) {
                $jasa->images->transform(function ($image) {
                    $image->path = $image->image_path;
                    $image->url = route('images.show', ['image' => $image->id]);
                    $image->src_url = $image->url;
                    $image->image_url = asset('storage/' . $image->image_path);

                    $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                    return $image;
                });

                // public endpoint => always public URL
                $this->attachCoverImg($jasa, true);
            }

            // Add rating_summary for each jasa
            $jasa->rating_summary = \App\Models\RatingSummary::getJasaRatingSummary($jasa->id);
        });

        return response()->json($jasas);
    }

    /**
     * PUBLIC: GET /api/public/jasas/{slug}
     * Detail jasa untuk halaman customer by slug (preferred endpoint).
     */
    public function publicShowBySlug($slug)
    {
        // Detail jasa untuk customer: hanya tampilkan jasa yang benar-benar dipublish.
        $jasa = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province:id,name',
            'merchant.primaryAddress.city:id,name',
            'merchant.primaryAddress.district:id,name',
            'merchant.primaryAddress.village:id,name',
            'images',
            'ratingSummary',
            'ratings.user',
            'ratings.media',
        ])
            ->where(function ($q) {
                $q->whereIn('status', ['published', 'active'])
                    ->orWhere(function ($sub) {
                        $sub->whereNull('status')->where('is_active', true);
                    });
            })
            ->where('slug', $slug)
            ->first();

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        $this->attachCategoryAliases($jasa);

        // Normalisasi struktur images untuk FE
        if ($jasa->images) {
            $jasa->images->transform(function ($image) {
                $image->path = $image->image_path;
                $image->url = route('images.show', ['image' => $image->id]);
                $image->src_url = $image->url;
                $image->image_url = asset('storage/' . $image->image_path);
                $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                return $image;
            });

            $this->attachCoverImg($jasa, true);
        }

        // API response now includes cara_pemesanan (DB value) — FE maps to display format

        // Calculate rating summary if not found via relationship
        $ratingSummary = $jasa->ratingSummary;
        $ratings = $jasa->ratings ?? collect();

        $jasa->rating_summary = [
            'average_rating' => $ratingSummary?->average_rating
                ?? ($ratings->isNotEmpty() ? round($ratings->avg('rating'), 1) : 0),
            'total_reviews' => $ratingSummary?->total_reviews ?? $ratings->count(),
            'rating_5_count' => $ratingSummary?->rating_5_count ?? $ratings->where('rating', 5)->count(),
            'rating_4_count' => $ratingSummary?->rating_4_count ?? $ratings->where('rating', 4)->count(),
            'rating_3_count' => $ratingSummary?->rating_3_count ?? $ratings->where('rating', 3)->count(),
            'rating_2_count' => $ratingSummary?->rating_2_count ?? $ratings->where('rating', 2)->count(),
            'rating_1_count' => $ratingSummary?->rating_1_count ?? $ratings->where('rating', 1)->count(),
        ];

        // Remove raw relationships from response, keep summary only
        $jasa->makeHidden(['ratingSummary', 'ratings']);

        return response()->json($jasa);
    }

    /**
     * PUBLIC: GET /api/public/jasas/{id}
     * Detail jasa untuk halaman customer, termasuk relasi merchant.
     */
    public function publicShow($id)
    {
        // Detail jasa untuk customer: hanya tampilkan jasa yang benar-benar dipublish.
        $jasa = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province:id,name',
            'merchant.primaryAddress.city:id,name',
            'merchant.primaryAddress.district:id,name',
            'merchant.primaryAddress.village:id,name',
            'images',
            'ratingSummary',
        ])
            ->where(function ($q) {
                $q->whereIn('status', ['published', 'active'])
                    ->orWhere(function ($sub) {
                        $sub->whereNull('status')->where('is_active', true);
                    });
            })
            ->where('id', $id)
            ->first();

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        $this->attachCategoryAliases($jasa);

        // Normalisasi struktur images untuk FE
        if ($jasa->images) {
            $jasa->images->transform(function ($image) {
                $image->path = $image->image_path;
                $image->url = route('images.show', ['image' => $image->id]);
                $image->src_url = $image->url;
                $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                return $image;
            });

            $this->attachCoverImg($jasa, true);
        }

        // API response now includes cara_pemesanan (DB value) — FE maps to display format

        // Calculate rating summary if not found via relationship
        $ratingSummary = $jasa->ratingSummary;
        $ratings = $jasa->ratings ?? collect();

        $jasa->rating_summary = [
            'average_rating' => $ratingSummary?->average_rating
                ?? ($ratings->isNotEmpty() ? round($ratings->avg('rating'), 1) : 0),
            'total_reviews' => $ratingSummary?->total_reviews ?? $ratings->count(),
            'rating_5_count' => $ratingSummary?->rating_5_count ?? $ratings->where('rating', 5)->count(),
            'rating_4_count' => $ratingSummary?->rating_4_count ?? $ratings->where('rating', 4)->count(),
            'rating_3_count' => $ratingSummary?->rating_3_count ?? $ratings->where('rating', 3)->count(),
            'rating_2_count' => $ratingSummary?->rating_2_count ?? $ratings->where('rating', 2)->count(),
            'rating_1_count' => $ratingSummary?->rating_1_count ?? $ratings->where('rating', 1)->count(),
        ];

        // Remove raw relationships from response, keep summary only
        $jasa->makeHidden(['ratingSummary', 'ratings']);

        return response()->json($jasa);
    }

    /**
     * POST /api/jasa
     * Tambah data jasa baru (admin input)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'merchant_id' => 'required|integer|exists:merchants,id',
            'title' => 'required|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'price' => 'required|integer|min:0',
            'image' => 'nullable|string|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'distance_km' => 'nullable|numeric|min:0',
            'duration_hours' => 'nullable|numeric|min:0',
            'description' => 'required|string|min:20',
            'is_active' => 'boolean',
        ]);

        // Pastikan merchant dimiliki oleh user yang login
        Merchant::where('id', (int) $validated['merchant_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

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
        \Log::info('[JasaController.update] RAW REQUEST price fields:', [
            'fixed_price' => $request->input('fixed_price'),
            'base_price'  => $request->input('base_price'),
            'price'       => $request->input('price'),
        ]);

        $jasa = Jasa::find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        // Normalize cara_pemesanan HANYA jika request memang mengirim field cara pemesanan.
        // Publish/unpublish biasanya hanya mengirim { status: "published" },
        // jadi jangan ubah cara_pemesanan saat field tidak dikirim.
        if (
            $request->has('booking_type') ||
            $request->has('cara_pemesanan') ||
            $request->has('service_type_booking')
        ) {
            $canonical =
                $request->input('booking_type')
                ?? $request->input('cara_pemesanan')
                ?? $request->input('service_type_booking');

            $canonical = strtolower(trim((string) $canonical));

            if (in_array($canonical, ['cart', 'keranjang', 'langsung_pesan', 'direct_checkout', 'direct'], true)) {
                $normalized = 'langsung_pesan';
                $serviceTypeBooking = 'keranjang';
            } elseif (in_array($canonical, ['booking', 'scheduled'], true)) {
                $normalized = 'booking';
                $serviceTypeBooking = 'booking';
            } elseif (in_array($canonical, ['konsultasi', 'consultation', 'memerlukan_konsultasi'], true)) {
                $normalized = 'memerlukan_konsultasi';
                $serviceTypeBooking = 'konsultasi';
            } else {
                $normalized = $jasa->cara_pemesanan ?: 'langsung_pesan';
                $serviceTypeBooking = match ($normalized) {
                    'booking' => 'booking',
                    'memerlukan_konsultasi' => 'konsultasi',
                    default => 'keranjang',
                };
            }

            $request->merge([
                'cara_pemesanan' => $normalized,
                'service_type_booking' => $serviceTypeBooking,
            ]);
        }

        // Also normalize service_type if sent (convert old values to new standard)
        $st = $request->input('service_type');
        if ($st === 'at_location' || $st === 'ditempat_saya') {
            $request->merge(['service_type' => 'di_tempat_umkm']);
        } elseif ($st === 'on_site' || $st === 'kerumah_pelanggan') {
            $request->merge(['service_type' => 'ke_rumah_pelanggan']);
        }
        // 'online' stays as 'online'

        // Validasi field lama + field baru yang dipakai di MerchantJasa (kategori, status, jadwal, dll)
        // Status mendukung skema lama (active/inactive) dan baru (published/archived)
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'price' => 'sometimes|required|integer|min:0',
            'image' => 'nullable|string|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'distance_km' => 'nullable|numeric|min:0',
            'duration_hours' => 'nullable|numeric|min:0',
            'description' => 'sometimes|required|string|min:20',
            'is_active' => 'boolean',

            // Field baru jasa merchant
            'fixed_price' => 'nullable|integer|min:0',
            'base_price' => 'nullable|integer|min:0',

            // FE: keranjang, booking, konsultasi | DB: langsung_pesan, booking, memerlukan_konsultasi
            'cara_pemesanan' => 'nullable|string|in:keranjang,booking,konsultasi,langsung_pesan,memerlukan_konsultasi,direct_checkout,consultation',
            'booking_type' => 'nullable|string|in:keranjang,booking,konsultasi,langsung_pesan,memerlukan_konsultasi,direct_checkout,consultation',

            // Tipe layanan - normalize to new standard format
            'service_type' => 'nullable|string|in:online,di_tempat_umkm,ke_rumah_pelanggan,at_location,on_site,ditempat_saya,kerumah_pelanggan',
            'location_address' => 'nullable|string|max:500',
            'special_notes' => 'nullable|string',
            'payment_methods' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:draft,active,inactive,published,archived',
            'operating_days' => 'nullable|string|max:255',
            'operating_times' => 'nullable|string|max:255',
            'jasa_category_id' => 'nullable|integer|exists:categories,id',
            'jasa_subcategory_id' => 'nullable|integer|exists:categories,id',

            // Gambar layanan (multiple file) dari Editjasa.vue (opsional)
            'images' => 'nullable|array|max:10',
            'images.*' => 'file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $data = $validated;

        // CARA PEMESANAN sudah dinormalisasi oleh $request->merge() di atas.
        // $data['cara_pemesanan'] sudah berisi nilai DB yang benar.
        // Hapus field frontend-only sebelum menyimpan ke tabel jasas.

        // Sinkronkan alamat layanan sesuai tipe layanan
        // service_type sudah dinormalisasi ke format baru di atas
        if (array_key_exists('service_type', $data)) {
            if ($data['service_type'] === 'di_tempat_umkm' || $data['service_type'] === 'at_location') {
                $merchant = $jasa->merchant;
                $primary = $merchant?->primary_address;

                $location = trim(implode(', ', array_filter([
                    data_get($primary, 'detail'),
                    data_get($primary, 'village'),
                    data_get($primary, 'district'),
                    data_get($primary, 'city'),
                    data_get($primary, 'province'),
                ])));

                if ($location === '') {
                    $location = $merchant->address ?? $merchant->alamat ?? '';
                }

                $data['location_address'] = $location;
            }

            if ($data['service_type'] === 'online') {
                $data['location_address'] = '';
            }

            // ke_rumah_pelanggan: location_address tetap nullable, customer isi saat booking
            if ($data['service_type'] === 'ke_rumah_pelanggan' || $data['service_type'] === 'on_site') {
                $data['location_address'] = '';
            }
        }

        // Kategori Jasa disimpan via pivot table `categorizables`
        unset($data['jasa_category_id'], $data['jasa_subcategory_id']);

        // Jika ada perubahan fixed/base price, sinkronkan ke kolom legacy price
        if (array_key_exists('fixed_price', $data) || array_key_exists('base_price', $data)) {
            $fixed = $data['fixed_price'] ?? $jasa->fixed_price;
            $base = $data['base_price'] ?? $jasa->base_price;
            $data['price'] = $fixed ?? $base ?? $jasa->price;
        }

        // Jika status dikirim, update is_active mengikuti status
        // - active / published  => is_active = true
        // - inactive / archived / draft / null => is_active = false
        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            $data['is_active'] = in_array($status, ['active', 'published']);
        }

        // Block re-publishing jasa that has active admin violation (archive_service)
        if (isset($data['status']) && in_array($data['status'], ['published', 'active'])) {
            $hasServiceSanction = \App\Models\ContentReport::whereIn('reportable_type', [
                $jasa->getMorphClass(),
                get_class($jasa)
            ])
                ->where('reportable_id', $jasa->id)
                ->whereIn('action_taken', ['archive_service', 'archive_product'])
                ->whereDoesntHave('appeals', fn($q) => $q->where('status', 'accepted'))
                ->exists();

            if ($hasServiceSanction) {
                return response()->json([
                    'message' => 'Layanan ini telah diarsipkan oleh Admin karena pelanggaran. Silakan ajukan sanggahan atau hubungi Admin untuk dapat mempublikasikannya kembali.',
                ], 403);
            }
        }

        // Hapus field frontend-only sebelum menyimpan ke tabel jasas
        unset($data['booking_type'], $data['order_method']);

        // === DEBUG: log data yang akan di-update ===
        \Log::info('[JasaController.update] $data before update:', $data);
        \Log::info('[JasaController.update] price fields:', [
            'fixed_price' => $data['fixed_price'] ?? 'NOT_IN_DATA',
            'base_price'  => $data['base_price']  ?? 'NOT_IN_DATA',
            'price'       => $data['price']       ?? 'NOT_IN_DATA',
        ]);

        $jasa->update($data);

        // === DEBUG: log data yang BENAR-BENAR disimpan ===
        $jasa->refresh();
        \Log::info('[JasaController.update] After update - saved values:', [
            'fixed_price' => $jasa->fixed_price,
            'base_price'  => $jasa->base_price,
            'price'       => $jasa->price,
        ]);

        // Sync categories (pivot) if provided
        $this->syncJasaCategoriesFromRequest($jasa, $request);

        // Hapus gambar yang ditandai untuk dihapus (jika ada)
        if ($request->filled('remove_images')) {
            $ids = json_decode($request->input('remove_images'), true);
            if (is_array($ids) && count($ids) > 0) {
                $images = $jasa->images()->whereIn('id', $ids)->get();
                foreach ($images as $image) {
                    if ($image->image_path && Storage::disk('public')->exists($image->image_path)) {
                        Storage::disk('public')->delete($image->image_path);
                    }
                    $image->delete();
                }
            }
        }

        // Tambah gambar baru (jika diupload)
        if ($request->hasFile('images')) {
            $files = $request->file('images');
        } elseif ($request->hasFile('images[]')) {
            $files = $request->file('images[]');
        } else {
            $files = [];
        }
        if (!empty($files)) {
            $currentMaxOrder = (int) $jasa->images()->max('display_order');
            $order = $currentMaxOrder >= 0 ? $currentMaxOrder + 1 : 0;

            foreach ($files as $file) {
                $path = $file->store("jasa/{$jasa->id}", 'public');

                $jasa->images()->create([
                    'image_path' => $path,
                    'display_order' => $order,
                    'is_cover' => false,
                ]);

                $order++;
            }
        }

        // Atur cover image jika dikirim dari FE
        if ($request->filled('cover_image_id')) {
            $coverId = (int) $request->input('cover_image_id');

            $jasa->images()->update(['is_cover' => false]);

            $coverImage = $jasa->images()->where('id', $coverId)->first();
            if ($coverImage) {
                $coverImage->is_cover = true;
                $coverImage->save();

                $jasa->image = $coverImage->image_path;
                $jasa->save();
            }
        } else {
            // Jika tidak ada cover_image_id eksplisit, pastikan ada satu cover
            $coverImage = $jasa->images()->where('is_cover', true)->first();

            if (!$coverImage) {
                $coverImage = $jasa->images()->orderBy('display_order')->first();
                if ($coverImage) {
                    $coverImage->is_cover = true;
                    $coverImage->save();
                }
            }

            if ($coverImage) {
                $jasa->image = $coverImage->image_path;
                $jasa->save();
            }
        }

        // Muat relasi dan tambahkan cover_img dengan API URL
        $jasa->load(['categories', 'images']);

        // Transform images: add url, src_url, image_url for each image
        $jasa->images->transform(function ($image) {
            $publicUrl = route('images.show', ['image' => $image->id]);
            $image->url = $publicUrl;
            $image->src_url = $publicUrl;
            $image->image_url = asset('storage/' . $image->image_path);
            $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
            return $image;
        });

        $this->attachCategoryAliases($jasa);

        $isPublic = in_array($jasa->status, ['published', 'active', 'archived'], true)
            || ($jasa->status === null && (bool) $jasa->is_active);
        $this->attachCoverImg($jasa, $isPublic);

        // API response now includes cara_pemesanan (DB value) — FE maps to display format

        return response()->json([
            'message' => 'Data jasa berhasil diperbarui',
            'data' => $jasa
        ]);
    }

    /**
     * OWNER / MERCHANT: Tambah jasa untuk merchant tertentu
     * Endpoint: POST /api/merchants/{merchantId}/jasas
     */
    public function storeForMerchant(Request $request, int $merchantId)
    {
        $user = $request->user();

        // Pastikan merchant dimiliki oleh user yang login
        $merchant = Merchant::where('id', $merchantId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Normalize booking type input to DB value for cara_pemesanan
        // Normalize booking type input to DB value for cara_pemesanan
        // Accepts: booking_type | cara_pemesanan (FE format values: keranjang, langsung_pesan, booking, konsultasi, dll.)
        // DB values for jasas.cara_pemesanan: langsung_pesan | booking | memerlukan_konsultasi
        $bt = $request->input('booking_type');
        $cp = $request->input('cara_pemesanan');
        $canonical = $bt ?? $cp ?? null;
        if (in_array($canonical, ['cart', 'keranjang', 'langsung_pesan', 'direct_checkout', 'direct'])) {
            $normalized = 'langsung_pesan';
        } elseif (in_array($canonical, ['booking', 'scheduled'])) {
            $normalized = 'booking';
        } elseif (in_array($canonical, ['konsultasi', 'consultation', 'memerlukan_konsultasi'])) {
            $normalized = 'memerlukan_konsultasi';
        } else {
            $normalized = 'langsung_pesan';
        }
        $request->merge(['cara_pemesanan' => $normalized]);

        // Normalize service_type (convert old values to new standard)
        $st = $request->input('service_type');
        if ($st === 'at_location' || $st === 'ditempat_saya') {
            $request->merge(['service_type' => 'di_tempat_umkm']);
        } elseif ($st === 'on_site' || $st === 'kerumah_pelanggan') {
            $request->merge(['service_type' => 'ke_rumah_pelanggan']);
        }
        // 'online' stays as 'online'

        // Validasi field sesuai form Createjasa.vue
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|min:20',

            // Harga
            'fixed_price' => 'nullable|integer|min:0',
            'base_price' => 'nullable|integer|min:0',

            // FE: keranjang, booking, konsultasi | DB: langsung_pesan, booking, memerlukan_konsultasi
            'cara_pemesanan' => 'nullable|string|in:keranjang,booking,konsultasi,langsung_pesan,memerlukan_konsultasi,direct_checkout,consultation',
            'booking_type' => 'nullable|string|in:keranjang,booking,konsultasi,langsung_pesan,memerlukan_konsultasi,direct_checkout,consultation',

            // Tipe & lokasi layanan
            'service_type' => 'required|string|in:online,di_tempat_umkm,ke_rumah_pelanggan,at_location,on_site,ditempat_saya,kerumah_pelanggan',
            'location_address' => 'nullable|string|max:500',
            'special_notes' => 'nullable|string',

            // Pembayaran & status
            'payment_methods' => 'nullable|string|max:255',
            'status' => 'required|string|in:draft,active,inactive,published,archived',
            'operating_days' => 'nullable|string|max:255',
            'operating_times' => 'nullable|string|max:255',

            // Kategori (nama field yang dipakai FE)
            'jasa_category_id' => 'nullable|integer|exists:categories,id',
            'jasa_subcategory_id' => 'nullable|integer|exists:categories,id',

            // Gambar layanan (multiple file) dari Createjasa.vue
            'images' => 'nullable|array|max:10',
            'images.*' => 'file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $data = $validated;

        // CARA PEMESANAN sudah dinormalisasi oleh $request->merge() di atas.
        // $data['cara_pemesanan'] sudah berisi nilai DB yang benar.
        $isBooking = $data['cara_pemesanan'] === 'booking';
        if ($isBooking && empty($data['operating_times'])) {
            return response()->json([
                'message' => 'Jam layanan wajib diisi untuk metode Booking',
                'errors' => ['operating_times' => ['Jam layanan wajib diisi agar customer bisa memilih jadwal']]
            ], 422);
        }

        // Sinkronkan alamat layanan sesuai tipe layanan
        if (($data['service_type'] ?? null) === 'di_tempat_umkm' || ($data['service_type'] ?? null) === 'at_location') {
            $primary = $merchant->primary_address;
            $location = trim(implode(', ', array_filter([
                data_get($primary, 'detail'),
                data_get($primary, 'village'),
                data_get($primary, 'district'),
                data_get($primary, 'city'),
                data_get($primary, 'province'),
            ])));

            if ($location === '') {
                $location = $merchant->address ?? $merchant->alamat ?? '';
            }

            $data['location_address'] = $location;
        }

        if (($data['service_type'] ?? null) === 'online' || ($data['service_type'] ?? null) === 'ke_rumah_pelanggan' || ($data['service_type'] ?? null) === 'on_site') {
            $data['location_address'] = '';
        }

        // Field jadwal sekarang opsional dari FE.
        // Default aman: tersedia setiap hari, tanpa batasan jam spesifik.
        if (empty($data['operating_days'])) {
            $data['operating_days'] = '1,2,3,4,5,6,7';
        }

        // Parse operating_times if it's a JSON string (sent from FE as JSON)
        $operatingTimes = $data['operating_times'] ?? '';
        if (is_string($operatingTimes) && !empty($operatingTimes)) {
            $decoded = json_decode($operatingTimes, true);
            // Only assign if it's a valid array
            if (is_array($decoded) && !empty($decoded)) {
                $data['operating_times'] = $decoded;
            } else {
                $data['operating_times'] = '';
            }
        } elseif (empty($operatingTimes)) {
            $data['operating_times'] = '';
        }

        // NORMALIZE PRICE FIELDS - Ensure fixed_price and base_price are never null
        // Convert empty strings to 0, handle nullable validation
        $data['fixed_price'] = $this->normalizePrice($data['fixed_price'] ?? null);
        $data['base_price'] = $this->normalizePrice($data['base_price'] ?? null);

        // Sinkronkan legacy price untuk kompatibilitas listing lama
        $data['price'] = $data['fixed_price'] > 0 ? $data['fixed_price'] : ($data['base_price'] > 0 ? $data['base_price'] : 0);

        // Set merchant_id dari path parameter
        $data['merchant_id'] = $merchant->id;

        // Atur is_active mengikuti status
        $status = $data['status'] ?? null;
        $data['is_active'] = in_array($status, ['active', 'published']);

        // Hapus field frontend-only sebelum menyimpan ke tabel jasas
        unset($data['booking_type'], $data['order_method']);

        $jasa = Jasa::create($data);

        // Sync categories via pivot
        $this->syncJasaCategoriesFromRequest($jasa, $request);

        // Simpan file gambar (jika ada) ke storage/app/public/jasa/{jasa_id}
        // dan gunakan file pertama sebagai cover ke kolom legacy `image`
        // FE mengirim sebagai images[] (array notation) karena Laravel validation rules: images.* => file|image|...
        if ($request->hasFile('images')) {
            $files = $request->file('images');
        } elseif ($request->hasFile('images[]')) {
            $files = $request->file('images[]');
        } else {
            $files = [];
        }
        if (!empty($files)) {
            if (!empty($files)) {
                $now = now();
                $imagesToInsert = [];

                foreach ($files as $index => $file) {
                    $path = $file->store("jasa/{$jasa->id}", 'public');

                    $imagesToInsert[] = [
                        'imageable_type' => 'jasa',
                        'imageable_id' => $jasa->id,
                        'image_path' => $path,
                        'display_order' => $index,
                        'is_cover' => $index === 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($index === 0) {
                        // sinkronkan legacy cover path
                        $jasa->image = $path;
                    }
                }

                if (!empty($imagesToInsert)) {
                    DB::table('images')->insert($imagesToInsert);
                }

                $jasa->save();
            }
        }

        // Muat relasi yang dipakai di Indexjasa.vue
        $jasa->load(['categories', 'images']);
        $this->attachCategoryAliases($jasa);

        // Tambahkan cover_img dengan API URL
        $isPublic = in_array($jasa->status, ['published', 'active', 'archived'], true)
            || ($jasa->status === null && (bool) $jasa->is_active);
        $this->attachCoverImg($jasa, $isPublic);

        return response()->json([
            'message' => 'Jasa berhasil dibuat',
            'data' => $jasa,
        ], 201);
    }

    /**
     * OWNER / MERCHANT: Tambah jasa untuk merchant tertentu (slug-based)
     * Endpoint: POST /api/merchants/{merchantSlug}/jasas
     */
    public function storeForMerchantBySlug(Request $request, string $merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)->firstOrFail();

        // Delegate to the id-based method (keeps ownership checks in one place)
        return $this->storeForMerchant($request, (int) $merchant->id);
    }

    /**
     * GET /api/jasas/{id}/available-slots?date=YYYY-MM-DD
     * Ambil slot waktu yang sudah terisi pada tanggal tertentu.
     * Digunakan oleh halaman booking untuk men-disable jam yang sudah dipesan.
     *
     * Status yang dianggap "terisi":
     *   menunggu_konfirmasi_merchant, diterima, layanan_dikerjakan,
     *   menunggu_konfirmasi_selesai
     *
     * Status yang TIDAK dianggap terisi:
     *   ditolak, dibatalkan, selesai
     */
    public function getAvailableSlots(Request $request, int $id)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $jasa = Jasa::find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        $date = $request->input('date');

        /*
    |--------------------------------------------------------------------------
    | Slot yang dianggap bebas
    |--------------------------------------------------------------------------
    | Slot hanya boleh dipakai ulang jika order sebelumnya batal, ditolak,
    | atau expired. Selain itu, slot tetap dikunci.
    */
        $freeStatuses = [
            'dibatalkan',
            'batal',
            'cancelled',
            'ditolak',
            'rejected',
            'expired',
        ];

        $bookingItems = JasaOrderItem::with(['order:id,status,payment_status,payment_method'])
            ->where('jasa_id', $id)
            ->where('booking_date', $date)
            ->whereIn('order_method', ['booking', 'scheduled'])
            ->whereNotNull('booking_time')
            ->whereHas('order', function ($query) use ($freeStatuses) {
                $query->whereNotIn('status', $freeStatuses);
            })
            ->get(['id', 'order_id', 'booking_time']);

        $slotStatuses = [];

        foreach ($bookingItems as $item) {
            $rawTime = trim((string) $item->booking_time);

            if ($rawTime === '') {
                continue;
            }

            // Normalisasi 07:00:00 / 07:00 / 07.00 menjadi 07.00
            $time = str_replace(':', '.', substr($rawTime, 0, 5));

            $orderStatus = strtolower((string) ($item->order?->status ?? ''));
            $paymentStatus = strtoupper((string) ($item->order?->payment_status ?? ''));

            /*
        |--------------------------------------------------------------------------
        | Tipe warna slot
        |--------------------------------------------------------------------------
        | pending  = orange, masih menunggu konfirmasi / belum dibayar
        | approved = merah, sudah diterima UMKM / sudah paid / sedang diproses
        */
            $isApproved = in_array($orderStatus, [
                'diterima',
                'layanan_dikerjakan',
                'menunggu_konfirmasi_selesai',
                'selesai',
                'completed',
            ], true) || in_array($paymentStatus, [
                'PAID',
                'SETTLED',
                'SUCCEEDED',
                'LUNAS',
                'SUDAH_BAYAR',
            ], true);

            $type = $isApproved ? 'approved' : 'pending';

            // Jika ada 2 data bentrok di jam yang sama, status approved menang.
            if (
                isset($slotStatuses[$time]) &&
                $slotStatuses[$time]['type'] === 'approved'
            ) {
                continue;
            }

            $slotStatuses[$time] = [
                'time' => $time,
                'type' => $type,
                'status' => $orderStatus,
                'payment_status' => $paymentStatus,
                'label' => $isApproved
                    ? 'Sudah disetujui UMKM'
                    : 'Menunggu konfirmasi UMKM',
            ];
        }

        return response()->json([
            'date' => $date,
            'jasa_id' => $id,

            // Backward compatible untuk FE lama
            'booked_slots' => array_keys($slotStatuses),

            // Data baru untuk warna bullet
            'slot_statuses' => array_values($slotStatuses),
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
