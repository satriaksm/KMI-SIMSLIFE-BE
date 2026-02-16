<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class JasaController extends Controller
{
    protected function attachCoverImg(Jasa $jasa, bool $isPublic): void
    {
        if (!$jasa->relationLoaded('images')) {
            $jasa->load('images');
        }

        $images = $jasa->images ?? collect();
        $cover = $images->firstWhere('is_cover', true) ?? $images->first();

        if (!$cover) {
            $jasa->setAttribute('cover_img', null);
            return;
        }

        $srcUrl = $isPublic
            ? route('images.show', ['image' => $cover->id])
            : URL::signedRoute('images.show', ['image' => $cover->id], now()->addMinutes(60));

        $jasa->setAttribute('cover_img', [
            'id' => $cover->id,
            'src_url' => $srcUrl,
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
        $query = Jasa::with(['categories', 'images'])
            // Tampilkan jasa lama di atas, yang baru di urutan terakhir
            ->orderBy('id', 'asc');

        // Optional filter by merchantId untuk tampilan owner
        if ($request->filled('merchantId')) {
            $query->where('merchant_id', $request->input('merchantId'));
        }

        $jasas = $query->get();

        $jasas->each(function ($jasa) {
            $this->attachCategoryAliases($jasa);
            
            // Normalize images for frontend
            if ($jasa->images && $jasa->images->count() > 0) {
                $isPublic = in_array($jasa->status, ['published', 'active', 'archived'], true)
                    || ($jasa->status === null && (bool) $jasa->is_active);

                $jasa->images->transform(function ($image) use ($isPublic) {
                    $image->path = $image->image_path;
                    $image->url = $isPublic
                        ? route('images.show', ['image' => $image->id])
                        : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));
                    $image->src_url = $image->url;

                    $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                    return $image;
                });
            }
        });

        // Selalu kembalikan array (termasuk [] jika kosong) agar frontend konsisten
        return response()->json($jasas);
    }

    public function show($id)
    {
        $jasa = Jasa::with(['categories', 'merchant', 'images'])->find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        // Tambahkan alias field untuk kompatibilitas FE baru (Create/Edit Jasa)
        // FE menggunakan jasa_category_id & jasa_subcategory_id
        $this->attachCategoryAliases($jasa);

        // Normalisasi struktur images untuk FE (path, is_cover, display_order)
        if ($jasa->images) {
            $isPublic = in_array($jasa->status, ['published', 'active', 'archived'], true)
                || ($jasa->status === null && (bool) $jasa->is_active);

            $jasa->images->transform(function ($image) use ($isPublic) {
                $image->path = $image->image_path;
                $image->url = $isPublic
                    ? route('images.show', ['image' => $image->id])
                    : URL::signedRoute('images.show', ['image' => $image->id], now()->addMinutes(60));
                $image->src_url = $image->url;

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

        $query = Jasa::with(['categories', 'merchant.segmentation', 'images'])
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

        $jasas = Jasa::with(['categories', 'merchant.segmentation', 'images'])
            ->where('merchant_id', $merchant->id)
            ->where(function ($q) {
                $q->whereIn('status', ['published', 'active'])
                    ->orWhere(function ($sub) {
                        $sub->whereNull('status')->where('is_active', true);
                    });
            })
            ->orderBy('id', 'desc')
            ->get();

        $jasas->each(function ($jasa) {
            $this->attachCategoryAliases($jasa);

            if ($jasa->images) {
                $jasa->images->transform(function ($image) {
                    $image->path = $image->image_path;
                    $image->url = route('images.show', ['image' => $image->id]);
                    $image->src_url = $image->url;

                    $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                    return $image;
                });

                // public endpoint => always public URL
                $this->attachCoverImg($jasa, true);
            }
        });

        return response()->json($jasas);
    }

    /**
     * PUBLIC: GET /api/public/jasas/{id}
     * Detail jasa untuk halaman customer, termasuk relasi merchant.
     */
    public function publicShow($id)
    {
        // Detail jasa untuk customer: hanya tampilkan jasa yang benar-benar dipublish.
        // Aturan sama seperti listing publik:
        // - Skema baru: status = 'published'
        // - Skema lama: status NULL dan is_active = true
        $jasa = Jasa::with([
            'categories',
            'merchant.segmentation',
            'merchant.primaryAddress',
            'images',
        ])
            ->where(function ($q) {
                $q->where('status', 'published')
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

        // Normalisasi struktur images untuk FE (path, is_cover, display_order)
        if ($jasa->images) {
            $jasa->images->transform(function ($image) {
                $image->path = $image->image_path;
                $image->url = route('images.show', ['image' => $image->id]);
                $image->src_url = $image->url;

                $image->makeHidden(['imageable_id', 'imageable_type', 'image_path', 'created_at', 'updated_at']);
                return $image;
            });

            // public endpoint => always public URL
            $this->attachCoverImg($jasa, true);
        }

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
            'description' => 'nullable|string',
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
        $jasa = Jasa::find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        // Validasi field lama + field baru yang dipakai di MerchantJasa (kategori, status, jadwal, dll)
        // Status mendukung skema lama (active/inactive) dan baru (published/archived)
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

            // Field baru jasa merchant
            'fixed_price' => 'nullable|integer|min:0',
            'base_price' => 'nullable|integer|min:0',
            'service_type' => 'nullable|string|in:at_location,on_site,online',
            'location_address' => 'nullable|string|max:255',
            'service_area' => 'nullable|string|max:255',
            'special_notes' => 'nullable|string',
            'payment_methods' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:draft,active,inactive,published,archived',
            'operating_days' => 'nullable|string|max:255',
            'operating_times' => 'nullable|string|max:255',
            'jasa_category_id' => 'nullable|integer|exists:categories,id',
            'jasa_subcategory_id' => 'nullable|integer|exists:categories,id',

            // Gambar layanan (multiple file) dari Editjasa.vue (opsional)
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $data = $validated;

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

        $jasa->update($data);

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

        // Validasi field sesuai form Createjasa.vue
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',

            // Harga
            'fixed_price' => 'nullable|integer|min:0',
            'base_price' => 'nullable|integer|min:0',

            // Tipe & lokasi layanan
            'service_type' => 'required|string|in:at_location,on_site,online',
            'location_address' => 'nullable|string|max:255',
            'service_area' => 'nullable|string|max:255',
            'special_notes' => 'nullable|string',

            // Pembayaran & status
            'payment_methods' => 'nullable|string|max:255',
            'status' => 'required|string|in:draft,active,inactive,published,archived',
            'operating_days' => 'required|string|max:255',
            'operating_times' => 'nullable|string|max:255',

            // Kategori (nama field yang dipakai FE)
            'jasa_category_id' => 'nullable|integer|exists:categories,id',
            'jasa_subcategory_id' => 'nullable|integer|exists:categories,id',

            // Gambar layanan (multiple file) dari Createjasa.vue
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $data = $validated;

        // Sinkronkan legacy price untuk kompatibilitas listing lama
        $fixed = $data['fixed_price'] ?? null;
        $base = $data['base_price'] ?? null;
        $data['price'] = $fixed ?? $base ?? 0;

        // Set merchant_id dari path parameter
        $data['merchant_id'] = $merchant->id;

        // Atur is_active mengikuti status
        $status = $data['status'] ?? null;
        $data['is_active'] = in_array($status, ['active', 'published']);

        $jasa = Jasa::create($data);

        // Sync categories via pivot
        $this->syncJasaCategoriesFromRequest($jasa, $request);

        // Simpan file gambar (jika ada) ke storage/app/public/jasa/{jasa_id}
        // dan gunakan file pertama sebagai cover ke kolom legacy `image`
        if ($request->hasFile('images')) {
            $files = $request->file('images');
            if (!empty($files)) {
                $now = now();
                $imagesToInsert = [];

                foreach ($files as $index => $file) {
                    $path = $file->store("jasa/{$jasa->id}", 'public');

                    $imagesToInsert[] = [
                        'imageable_type' => 'App\\Models\\Jasa',
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
        $jasa->load(['categories']);
        $this->attachCategoryAliases($jasa);

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
