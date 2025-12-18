<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use App\Models\Package;
use App\Models\JasaImage;
use Illuminate\Support\Facades\Storage;
use App\Models\Merchant;
use Illuminate\Http\Request;

class JasaController extends Controller
{
    private const JASA_SEGMENT_ID = 3; // ✅ sesuaikan jika id segmentasi UMKM Jasa berbeda

    // ============================================================
    // PUBLIC (CUSTOMER)
    // ============================================================

    // GET /api/jasa
    public function index()
    {
        $jasas = Jasa::query()
            ->with(['packages','images'])
            ->where('is_active', true)
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved')
                  ->where('segmentation_id', self::JASA_SEGMENT_ID);
            })
            ->orderByDesc('id')
            ->get();

        // Debug: Log the query and count
        \Log::debug('[JasaController@index] Query Result:', [
            'total_jasa_count' => Jasa::count(),
            'active_jasa_count' => Jasa::where('is_active', true)->count(),
            'returned_count' => count($jasas),
            'jasa_data' => $jasas->map(function($j) {
                return [
                    'id' => $j->id,
                    'title' => $j->title,
                    'is_active' => $j->is_active,
                    'merchant_id' => $j->merchant_id,
                    'merchant_status' => $j->merchant?->status ?? 'NO MERCHANT',
                    'merchant_segmentation_id' => $j->merchant?->segmentation_id ?? 'NO MERCHANT',
                ];
            })->toArray()
        ]);

        return response()->json($jasas);
    }

    // GET /api/jasa/{id}
    public function show($id)
    {
        $jasa = Jasa::query()
            ->with(['packages','images', 'category', 'subcategory'])
            ->where('is_active', true)
            ->whereHas('merchant', function ($q) {
                $q->where('status', 'approved')
                  ->where('segmentation_id', self::JASA_SEGMENT_ID);
            })
            ->find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
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
                $path = $file->store('public/jasa');
                JasaImage::create([
                    'jasa_id' => $jasa->id,
                    'path' => Storage::url($path),
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

        $jasa->update($validated);

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
        // Simpan ke folder "public/jasa" (tanpa s) agar konsisten dengan struktur existing
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                if (!$file->isValid()) continue;
                $path = $file->store('public/jasa');
                $jasa->images()->create([
                    'path' => Storage::url($path),
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
