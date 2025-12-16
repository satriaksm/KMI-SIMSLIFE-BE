<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use App\Models\Package;
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
            ->with('packages')
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
            ->with('packages')
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
        $query = Jasa::with('packages')
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

        $jasa = Jasa::with('packages')
            ->where('merchant_id', $merchant->id)
            ->find($id);

        if (!$jasa) {
            return response()->json(['message' => 'Jasa tidak ditemukan'], 404);
        }

        return response()->json($jasa);
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
            'price_type' => 'required|in:per_jam,per_sesi,per_hari,per_project',
            'base_price' => 'required|integer|min:0',
            'min_order' => 'required|integer|min:1',
            'negotiable' => 'boolean',
            
            // Duration & Hours
            'estimated_duration' => 'nullable|string',
            'operating_hours_start' => 'nullable|date_format:H:i',
            'operating_hours_end' => 'nullable|date_format:H:i',
            'operating_days' => 'nullable|string',
            'booking_advance_days' => 'nullable|integer|min:0',
            
            // Location & Service Area
            'service_type' => 'required|in:on_site,at_location,online',
            'location_address' => 'nullable|string',
            'service_area' => 'nullable|string',
            
            // Capacity & Limits
            'capacity_per_slot' => 'nullable|integer|min:1',
            'max_orders_per_day' => 'nullable|integer|min:1',
            
            // Terms & Conditions
            'cancellation_policy' => 'nullable|string',
            'customer_requirements' => 'nullable|string',
            'special_notes' => 'nullable|string',
            
            // Media & Support
            'portfolio' => 'nullable|string',
            'social_media' => 'nullable|string',
            
            // Admin
            'status' => 'nullable|in:draft,active,inactive',
            'internal_code' => 'nullable|string|unique:jasas,internal_code',
            'priority' => 'nullable|integer',
            'is_featured' => 'boolean',
            'image' => 'nullable|string|max:500',
        ]);

        $validated['merchant_id'] = $merchant->id;
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['negotiable'] = $request->boolean('negotiable', false);
        $validated['is_featured'] = $request->boolean('is_featured', false);

        $jasa = Jasa::create($validated);

        return response()->json([
            'message' => 'Data jasa berhasil ditambahkan',
            'data' => $jasa->load(['category', 'subcategory'])
        ], 201);
    }

        return response()->json([
            'message' => 'Data jasa berhasil ditambahkan',
            'data' => $jasa->load('packages')
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
            'price_type' => 'sometimes|required|in:per_jam,per_sesi,per_hari,per_project',
            'base_price' => 'sometimes|required|integer|min:0',
            'min_order' => 'sometimes|required|integer|min:1',
            'negotiable' => 'boolean',
            
            // Duration & Hours
            'estimated_duration' => 'nullable|string',
            'operating_hours_start' => 'nullable|date_format:H:i',
            'operating_hours_end' => 'nullable|date_format:H:i',
            'operating_days' => 'nullable|string',
            'booking_advance_days' => 'nullable|integer|min:0',
            
            // Location & Service Area
            'service_type' => 'sometimes|required|in:on_site,at_location,online',
            'location_address' => 'nullable|string',
            'service_area' => 'nullable|string',
            
            // Capacity & Limits
            'capacity_per_slot' => 'nullable|integer|min:1',
            'max_orders_per_day' => 'nullable|integer|min:1',
            
            // Terms & Conditions
            'cancellation_policy' => 'nullable|string',
            'customer_requirements' => 'nullable|string',
            'special_notes' => 'nullable|string',
            
            // Media & Support
            'portfolio' => 'nullable|string',
            'social_media' => 'nullable|string',
            
            // Admin
            'status' => 'nullable|in:draft,active,inactive',
            'internal_code' => 'nullable|string|unique:jasas,internal_code,' . $id,
            'priority' => 'nullable|integer',
            'is_featured' => 'boolean',
            'image' => 'nullable|string|max:500',
        ]);

        $jasa->update($validated);

        return response()->json([
            'message' => 'Data jasa berhasil diperbarui',
            'data' => $jasa->fresh()->load(['category', 'subcategory'])
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
