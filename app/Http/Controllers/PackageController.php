<?php

namespace App\Http\Controllers;

use App\Models\Jasa;
use App\Models\Package;
use App\Models\Merchant;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * GET /api/jasas/{jasaId}/packages
     * Owner: list paket berdasarkan jasa miliknya
     */
    public function index(Request $request, int $jasaId)
    {
        $jasa = $this->findOwnedJasaOrAbort($request, $jasaId);
        if (isset($jasa['error'])) return $jasa['error'];

        $packages = Package::where('jasa_id', $jasa->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($packages);
    }

    /**
     * POST /api/jasas/{jasaId}/packages
     * Owner: tambah paket untuk jasa miliknya
     */
    public function store(Request $request, int $jasaId)
    {
        $jasa = $this->findOwnedJasaOrAbort($request, $jasaId);
        if (isset($jasa['error'])) return $jasa['error'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:1000'],
        ]);

        $package = Package::create([
            'jasa_id' => $jasa->id,
            'name' => $data['name'],
            'price' => $data['price'],
            'image' => $data['image'] ?? null,
        ]);

        return response()->json([
            'message' => 'Paket berhasil ditambahkan.',
            'data' => $package,
        ], 201);
    }

    /**
     * PUT /api/packages/{id}
     * Owner: update paket (pastikan paket milik jasa miliknya)
     */
    public function update(Request $request, int $id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['message' => 'Paket tidak ditemukan.'], 404);
        }

        $jasa = $this->findOwnedJasaOrAbort($request, (int) $package->jasa_id);
        if (isset($jasa['error'])) return $jasa['error'];

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:1000'],
        ]);

        $package->update([
            'name' => $data['name'] ?? $package->name,
            'price' => array_key_exists('price', $data) ? $data['price'] : $package->price,
            'image' => array_key_exists('image', $data) ? $data['image'] : $package->image,
        ]);

        return response()->json([
            'message' => 'Paket berhasil diperbarui.',
            'data' => $package->fresh(),
        ]);
    }

    /**
     * DELETE /api/packages/{id}
     * Owner: hapus paket (pastikan paket milik jasa miliknya)
     */
    public function destroy(Request $request, int $id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['message' => 'Paket tidak ditemukan.'], 404);
        }

        $jasa = $this->findOwnedJasaOrAbort($request, (int) $package->jasa_id);
        if (isset($jasa['error'])) return $jasa['error'];

        $package->delete();

        return response()->json(['message' => 'Paket berhasil dihapus.']);
    }

    // ============================================================
    // HELPER: Validasi jasa milik merchant owner
    // ============================================================
    private function findOwnedJasaOrAbort(Request $request, int $jasaId)
    {
        $user = $request->user();

        $jasa = Jasa::find($jasaId);
        if (!$jasa) {
            return ['error' => response()->json(['message' => 'Jasa tidak ditemukan.'], 404)];
        }

        if (empty($jasa->merchant_id)) {
            return ['error' => response()->json(['message' => 'Jasa belum terhubung ke merchant.'], 422)];
        }

        $merchant = Merchant::where('id', $jasa->merchant_id)->first();
        if (!$merchant) {
            return ['error' => response()->json(['message' => 'Merchant untuk jasa ini tidak ditemukan.'], 404)];
        }

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ['error' => response()->json(['message' => 'Akses ditolak. Anda bukan pemilik jasa ini.'], 403)];
        }

        if ($merchant->status !== 'approved') {
            return ['error' => response()->json(['message' => 'UMKM belum disetujui admin.'], 403)];
        }

        // OPTIONAL: kalau kamu mau batasi segment UMKM Jasa saja, aktifkan ini
        // contoh: segmentation_id = 3 adalah "UMKM Jasa"
        // if ((int) $merchant->segmentation_id !== 3) {
        //     return ['error' => response()->json(['message' => 'Merchant ini bukan UMKM Jasa.'], 403)];
        // }

        return $jasa;
    }
}
