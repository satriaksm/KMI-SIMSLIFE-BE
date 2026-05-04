<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Jasa;
use App\Models\Package;
use App\Models\Promo;
use App\Models\Merchant;
use Illuminate\Http\Request;

class OrderController extends Controller
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
            'jasa_id' => 'required|exists:jasas,id',
            'nama' => 'required|string|max:255',
            'tel' => 'required|string|max:20',
            'alamat' => 'required|string',
            'tanggal' => 'required|date',
            'waktu' => 'required|string',
            'metode_pembayaran' => 'required|in:COD,QRIS',
            'promo_code' => 'nullable|string',
            'total' => 'required|integer',
            'status' => 'in:pending,proses,selesai,batal'
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
