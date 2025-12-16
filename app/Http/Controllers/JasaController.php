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

        // Accept both 'name' and 'title' for flexibility with frontend
        $titleField = $request->filled('name') ? 'name' : 'title';
        
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'price' => 'required|integer|min:0',
            'image' => 'nullable|string|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'distance_km' => 'nullable|numeric|min:0',
            'duration_hours' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Map 'name' to 'title' if provided
        if ($request->filled('name')) {
            $validated['title'] = $request->input('name');
            unset($validated['name']);
        }

        // Ensure title is set
        if (empty($validated['title'])) {
            return response()->json(['message' => 'Title atau name harus diisi'], 422);
        }

        // Default publish to active when not explicitly provided
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['merchant_id'] = $merchant->id;

        $jasa = Jasa::create($validated);

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
            'title' => 'sometimes|required|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'price' => 'sometimes|required|integer|min:0',
            'image' => 'nullable|string|max:500',
            'rating' => 'nullable|numeric|min:0|max:5',
            'distance_km' => 'nullable|numeric|min:0',
            'duration_hours' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $jasa->update($validated);

        return response()->json([
            'message' => 'Data jasa berhasil diperbarui',
            'data' => $jasa->fresh()->load('packages')
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
