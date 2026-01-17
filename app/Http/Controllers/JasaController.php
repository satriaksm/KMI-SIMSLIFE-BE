<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use App\Models\Package;
use App\Models\JasaImage;
use Illuminate\Support\Facades\Storage;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class JasaController extends Controller
{
    private const JASA_SEGMENT_ID = 3; // ✅ sesuaikan jika id segmentasi UMKM Jasa berbeda

    // ============================================================
    // PUBLIC (CUSTOMER)
    // ============================================================

    // GET /api/jasa
    public function index(Request $request)
    {
        $query = Jasa::query()
            ->with(['packages','images','category','subcategory'])
            ->where('is_active', true)
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved')
                  ->where('segmentation_id', self::JASA_SEGMENT_ID);
            });

        // Filter by merchant_id jika ada
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        $jasas = $query->orderByDesc('id')->get();

        return response()->json($jasas);
    }

    // GET /api/jasa/{id}
    public function show($id)
    {
        $jasa = Jasa::query()
            ->with([
                'packages',
                'images', 
                'category', 
                'subcategory',
                'merchant:id,name,logo_path,status,segmentation_id',
                'merchant.segmentation:id,name'
            ])
            ->where('is_active', true)
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved')
                  ->where('segmentation_id', self::JASA_SEGMENT_ID);
            })
            ->find($id);
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
        $query = Jasa::with(['categories'])
            // Tampilkan jasa lama di atas, yang baru di urutan terakhir
            ->orderBy('id', 'asc');

        // Optional filter by merchantId untuk tampilan owner
        if ($request->filled('merchantId')) {
            $query->where('merchant_id', $request->input('merchantId'));
        }

        $jasas = $query->get();

        $jasas->each(function ($jasa) {
            $this->attachCategoryAliases($jasa);
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

    // ============================================================
    // OWNER (ADMIN UMKM JASA)
    // ============================================================

    // GET /api/jasas/owner
    public function ownerIndex(Request $request)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        // Build query dengan pagination dan filtering
        $query = Jasa::with(['packages', 'images', 'category', 'subcategory'])
            ->where('merchant_id', $merchant->id);

        // Search by title
        if ($request->filled('q')) {
            $query->where('title', 'like', '%' . $request->q . '%');
        }

        // Filter by status (is_active)
        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'published') {
                $query->where('is_active', true);
            } elseif ($status === 'archived' || $status === 'draft') {
                $query->where('is_active', false);
            }
        }

        // Filter by price range
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'newest');
        switch ($sortBy) {
            case 'oldest':
                $query->orderBy('id', 'asc');
                break;
            case 'name_asc':
                $query->orderBy('title', 'asc');
                break;
            case 'name_desc':
                $query->orderBy('title', 'desc');
                break;
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'newest':
            default:
                $query->orderByDesc('id');
                break;
        }

        // Pagination
        $perPage = $request->input('per_page', 15);
        $jasas = $query->paginate($perPage);

        return response()->json([
            'data' => $jasas->items(),
            'meta' => [
                'current_page' => $jasas->currentPage(),
                'last_page' => $jasas->lastPage(),
                'per_page' => $jasas->perPage(),
                'total' => $jasas->total(),
                'from' => $jasas->firstItem(),
                'to' => $jasas->lastItem(),
            ]
        ]);
    }

    // GET /api/jasas/owner/{id}
    public function ownerShow(Request $request, $id)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $jasa = Jasa::with(['packages', 'images', 'category', 'subcategory'])
            ->where('merchant_id', $merchant->id)
            ->find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        // Bungkus dalam key "data" dan pastikan dikonversi ke array
        // untuk menghindari masalah serialisasi JSON yang sempat muncul di log.
        return response()->json([
            'data' => $jasa->toArray(),
        ]);
    }

    // POST /api/jasas (owner create)
    public function store(Request $request)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $validated = $request->validate([
            // Basic Info
        $user = $request->user();

        $validated = $request->validate([
            'merchant_id' => 'required|integer|exists:merchants,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            
            // Category & Subcategory
            'jasa_category_id' => 'required|exists:jasa_categories,id',
            'jasa_subcategory_id' => 'nullable|exists:jasa_subcategories,id',
            
            // Pricing
            'fixed_price' => 'required|integer|min:0',
            'base_price' => 'required|integer|min:0',
            
            // Location & Service Area
            'service_type' => 'required|in:on_site,at_location,online',
            'location_address' => 'nullable|string',
            'service_area' => 'nullable|string',
            
            // Operating Days & Times
            'operating_days' => 'nullable|string',
            'operating_times' => 'nullable|string',
            
            // Special Notes
            'special_notes' => 'nullable|string',
            
            // Payment
            'payment_methods' => 'nullable|string',
            
            // Admin
            'status' => 'nullable|in:draft,active,inactive',
        ]);

        $validated['merchant_id'] = $merchant->id;
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['is_featured'] = $request->boolean('is_featured', false);
        // Pastikan merchant dimiliki oleh user yang login
        Merchant::where('id', (int) $validated['merchant_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $jasa = Jasa::create($validated);

        // Handle images (optional) - accept multiple files under key 'images'
        // Simpan ke folder "public/jasa" (tanpa s) agar konsisten dengan struktur existing
        \Log::info('[JasaController@store] Incoming images info', [
            'content_type' => $request->header('Content-Type'),
            'has_images' => $request->hasFile('images'),
            'all_files_keys' => array_keys($request->allFiles()),
            'all_input_keys' => array_keys($request->all()),
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                if (!$file->isValid()) continue;
                $path = $file->store('jasa', 'public');
                JasaImage::create([
                    'jasa_id' => $jasa->id,
                    'path' => '/storage/' . $path,
                    'is_cover' => $index === 0,
                ]);
            }
        }

        return response()->json([
            'message' => 'Data jasa berhasil ditambahkan',
            'data' => $jasa->load(['category', 'subcategory', 'packages', 'images'])
        ], 201);
    }

    // PUT /api/jasas/{id} (owner update)
    public function update(Request $request, $id)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $jasa = Jasa::where('merchant_id', $merchant->id)->find($id);
        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        // Validasi field lama + field baru yang dipakai di MerchantJasa (kategori, status, jadwal, dll)
        // Status mendukung skema lama (active/inactive) dan baru (published/archived)
        $validated = $request->validate([
            // Basic Info
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            
            // Category & Subcategory
            'jasa_category_id' => 'sometimes|required|exists:jasa_categories,id',
            'jasa_subcategory_id' => 'nullable|exists:jasa_subcategories,id',
            
            // Pricing
            'fixed_price' => 'sometimes|required|integer|min:0',
            'base_price' => 'sometimes|required|integer|min:0',
            
            // Location & Service Area
            'service_type' => 'sometimes|required|in:on_site,at_location,online',
            'location_address' => 'nullable|string',
            'service_area' => 'nullable|string',
            
            // Operating Days & Times
            'operating_days' => 'nullable|string',
            'operating_times' => 'nullable|string',
            
            // Special Notes
            'special_notes' => 'nullable|string',
            
            // Admin
            'status' => 'nullable|in:draft,active,inactive',
        ]);

        \Log::info('[JasaController@update] Before update', [
            'jasa_id' => $jasa->id,
            'old_status' => $jasa->status,
            'old_is_active' => $jasa->is_active,
            'validated_status' => $validated['status'] ?? 'not set',
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

        // Sync is_active based on status
        if (isset($validated['status'])) {
            $jasa->is_active = ($validated['status'] === 'active');
            $jasa->save();
            
            \Log::info('[JasaController@update] After status sync', [
                'jasa_id' => $jasa->id,
                'new_status' => $jasa->status,
                'new_is_active' => $jasa->is_active,
            ]);
        }

        // Append new images if provided
        // Simpan ke folder "jasa" di disk public
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                if (!$file->isValid()) continue;
                $path = $file->store('jasa', 'public');
                $jasa->images()->create([
                    'path' => '/storage/' . $path,
                    'is_cover' => false,
                ]);
            }
        }

        return response()->json([
            'message' => 'Data jasa berhasil diperbarui',
            'data' => $jasa->fresh()->load(['category', 'subcategory', 'packages', 'images'])
        ]);
    }

    // DELETE /api/jasas/{id} (owner delete)
    public function destroy(Request $request, $id)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $jasa = Jasa::where('merchant_id', $merchant->id)->find($id);
        if (!$jasa) {
            return response()->json(['message' => 'Data jasa tidak ditemukan'], 404);
        }

        $jasa->delete();

        return response()->json(['message' => 'Data jasa berhasil dihapus']);
    }

    // ============================================================
    // OWNER PACKAGES (Paket Jasa)
    // ============================================================

    // POST /api/jasas/{jasaId}/packages
    public function addPackage(Request $request, $jasaId)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $jasa = Jasa::where('merchant_id', $merchant->id)->find($jasaId);
        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'image' => 'nullable|string|max:500',
        ]);

        $pkg = $jasa->packages()->create($data);

        return response()->json([
            'message' => 'Paket berhasil ditambahkan',
            'data' => $pkg
        ], 201);
    }

    // PUT /api/packages/{id}
    public function updatePackage(Request $request, $id)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $pkg = Package::query()
            ->whereHas('jasa', fn($q) => $q->where('merchant_id', $merchant->id))
            ->find($id);

        if (!$pkg) {
            return response()->json(['message' => 'Paket tidak ditemukan'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|integer|min:0',
            'image' => 'nullable|string|max:500',
        ]);

        $pkg->update($data);

        return response()->json([
            'message' => 'Paket berhasil diperbarui',
            'data' => $pkg
        ]);
    }

    // DELETE /api/packages/{id}
    public function deletePackage(Request $request, $id)
    {
        $merchant = $this->getOwnerMerchantOrAbort($request);

        $pkg = Package::query()
            ->whereHas('jasa', fn($q) => $q->where('merchant_id', $merchant->id))
            ->find($id);

        if (!$pkg) {
            return response()->json(['message' => 'Paket tidak ditemukan'], 404);
        }

        $pkg->delete();

        return response()->json(['message' => 'Paket berhasil dihapus']);
    }

    // ============================================================
    // HELPER
    // ============================================================

    private function getOwnerMerchantOrAbort(Request $request): Merchant
    {
        $user = $request->user();

        // minimal check role umkm-owner (sesuai implementasi kamu)
        $isOwner = $user->roles()->whereRaw('LOWER(name) = ?', ['umkm-owner'])->exists();
        if (!$isOwner) {
            abort(403, 'Akses ditolak. Hanya UMKM Owner.');
        }

        // If merchantId is provided in request (from query param or route), validate that merchant
        $merchantId = $request->input('merchantId') ?? $request->route('merchantId');
        
        $query = Merchant::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('segmentation_id', self::JASA_SEGMENT_ID);

        // If specific merchantId requested, filter by it
        if ($merchantId) {
            $query->where('id', $merchantId);
        }

        $merchant = $query->first();

        if (!$merchant) {
            abort(403, 'UMKM Jasa belum tersedia/approved untuk user ini.');
        }

        return $merchant;
    }
}
