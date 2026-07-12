<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Jasa;
use App\Models\Package;
use App\Models\Promo;
use App\Models\Merchant;
use Illuminate\Http\Request;

class JasaOrderItemController extends Controller
{
    // ============================================================
    // CUSTOMER
    // ============================================================

    /**
     * GET /api/orders/mine
     * Customer lihat order miliknya sendiri
     */
    public function myOrders(Request $request)
    {
        return Order::with(['jasa', 'package'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();
    }

    /**
     * GET /api/orders/{id}
     * Customer lihat detail order miliknya
     */
    public function myOrderShow(Request $request, int $id)
    {
        $order = Order::with(['jasa', 'package'])->find($id);
        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }

        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json($order);
    }

    /**
     * POST /api/orders
     * Customer buat order jasa (alamat + catatan alamat + pilih COD/QRIS)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'jasa_id' => ['required', 'exists:jasas,id'],
            'package_id' => ['nullable', 'exists:packages,id'],

            'nama' => ['required', 'string', 'max:255'],
            'tel' => ['required', 'string', 'max:20'],
            'alamat' => ['required', 'string'],
            'catatan' => ['nullable', 'string'],
            'catatan_alamat' => ['nullable', 'string'],

            'tanggal' => ['required', 'date'],
            'waktu' => ['required', 'string'],

            'metode_pembayaran' => ['required', 'in:COD,QRIS'],
            'promo_code' => ['nullable', 'string', 'max:50'],
        ]);

        $jasa = Jasa::with('packages')->findOrFail($data['jasa_id']);


        // Validasi package harus milik jasa
        $package = null;
        if (!empty($data['package_id'])) {
            $package = Package::where('id', $data['package_id'])
                ->where('jasa_id', $jasa->id)
                ->first();

            if (!$package) {
                return response()->json(['message' => 'Paket tidak valid untuk jasa ini.'], 422);
            }
        }

        // Hitung total
        $total = $package ? (int) $package->price : (int) $jasa->price;

        // Apply promo (kalau ada)
        $appliedPromo = null;
        if (!empty($data['promo_code'])) {
            $promo = Promo::where('code', $data['promo_code'])
                ->where('is_active', true)
                ->first();

            if ($promo) {
                $appliedPromo = $promo->code;

                if ($promo->type === 'percent') {
                    $total = (int) round($total - ($total * ((int) $promo->value / 100)));
                } else { // flat
                    $total = max(0, $total - (int) $promo->value);
                }
            }
        }

        $order = Order::create([
            'user_id' => $request->user()->id,
            'jasa_id' => $jasa->id,
            'package_id' => $package?->id,

            'nama' => $data['nama'],
            'tel' => $data['tel'],
            'alamat' => $data['alamat'],
            'catatan' => $data['catatan'] ?? null,
            'catatan_alamat' => $data['catatan_alamat'] ?? null,

            'tanggal' => $data['tanggal'],
            'waktu' => $data['waktu'],
            'metode_pembayaran' => $data['metode_pembayaran'],
            'promo_code' => $appliedPromo,

            'total' => $total,
            'status' => 'pending',
        ]);

        return response()->json($order->load(['jasa', 'package']), 201);
    }

    // ============================================================
    // OWNER (UMKM-OWNER) - lihat order masuk berdasarkan merchant jasa
    // ============================================================

    /**
     * GET /api/orders/owner
     * Owner lihat semua order masuk untuk semua jasa milik merchant-nya
     */
    public function ownerIndex(Request $request)
    {
        $merchant = $this->findOwnedMerchantOrAbort($request);
        if (isset($merchant['error']))
            return $merchant['error'];

        return Order::with(['jasa', 'package'])
            ->whereHas('jasa', fn($q) => $q->where('merchant_id', $merchant->id))
            ->latest()
            ->get();
    }

    /**
     * GET /api/orders/owner/{id}
     * Owner lihat detail order masuk (harus punya merchant)
     */
    public function ownerShow(Request $request, int $id)
    {
        $merchant = $this->findOwnedMerchantOrAbort($request);
        if (isset($merchant['error']))
            return $merchant['error'];

        $order = Order::with(['jasa', 'package'])->find($id);
        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }

        if (!$order->jasa || (int) $order->jasa->merchant_id !== (int) $merchant->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json($order);
    }

    /**
     * PATCH /api/orders/owner/{id}/status
     * Owner update status order masuk (pending/proses/selesai/batal)
     */
    public function ownerUpdateStatus(Request $request, int $id)
    {
        $merchant = $this->findOwnedMerchantOrAbort($request);
        if (isset($merchant['error']))
            return $merchant['error'];

        $data = $request->validate([
            'status' => ['required', 'in:pending,proses,selesai,batal'],
        ]);

        $order = Order::with('jasa')->find($id);
        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }

        if (!$order->jasa || (int) $order->jasa->merchant_id !== (int) $merchant->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $order->update(['status' => $data['status']]);

        return response()->json([
            'message' => 'Status order berhasil diperbarui.',
            'data' => $order->fresh()->load(['jasa', 'package']),
        ]);
    }

    // ============================================================
    // ADMIN (optional)
    // ============================================================

    /**
     * GET /api/admin/orders
     */
    public function adminIndex()
    {
        return Order::with(['jasa', 'package'])->latest()->get();
    }

    /**
     * GET /api/admin/orders/{id}
     */
    public function adminShow(int $id)
    {
        $order = Order::with(['jasa', 'package'])->find($id);
        if (!$order) {
            return response()->json(['message' => 'Order tidak ditemukan.'], 404);
        }
        return response()->json($order);
    }

    // ============================================================
    // LEGACY ADMIN METHODS (kalau kamu masih butuh)
    // ============================================================

    public function index()
    {
        return Order::with(['jasa', 'package'])->latest()->get();
    }

    public function show($id)
    {
        $order = Order::with(['jasa', 'package'])->findOrFail($id);
        return response()->json($order);
    }

    // ============================================================
    // HELPER: detect merchant milik owner
    // ============================================================
    private function findOwnedMerchantOrAbort(Request $request)
    {
        $user = $request->user();

        $merchant = Merchant::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (!$merchant) {
            return ['error' => response()->json(['message' => 'Merchant tidak ditemukan / belum approved.'], 403)];
        }

        // OPTIONAL: kalau mau khusus UMKM Jasa saja, aktifkan:
        // if ((int) $merchant->segmentation_id !== 3) {
        //     return ['error' => response()->json(['message' => 'Merchant ini bukan UMKM Jasa.'], 403)];
        // }

        return $merchant;
    }
}