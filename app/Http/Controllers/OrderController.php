<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Order;
use App\Models\Jasa;
use App\Models\Package;
use App\Models\Promo;
use App\Models\Merchant;
use App\Models\ServiceOrder;
use App\Services\JasaOrderBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        return Order::with(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation'])
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
        $order = Order::with(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation'])->find($id);
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

        $serviceOrder = null;
        $order = null;

        DB::transaction(function () use (&$serviceOrder, &$order, $data, $request) {
            $jasa = Jasa::with('merchant')->findOrFail($data['jasa_id']);

            $serviceOrder = ServiceOrder::create([
                'customer_id' => $request->user()->id,
                'merchant_id' => $jasa->merchant_id,
                'jasa_id' => $jasa->id,
                'service_name' => $jasa->title,
                'service_type' => $jasa->service_type ?? $jasa->service_type_booking ?? null,
                'service_image' => $jasa->cover_img?->url ?? ($jasa->image ? asset('storage/' . $jasa->image) : null),
                'merchant_name' => $jasa->merchant?->name ?? 'UMKM',
                'total_price' => $data['total'],
                'status' => ServiceOrder::STATUS_MENUNGGU_KONFIRMASI,
                'booking_date' => $data['tanggal'],
                'booking_time' => $data['waktu'],
                'booking_note' => null,
                'customer_name' => $data['nama'],
                'customer_phone' => $data['tel'],
                'customer_address' => $data['alamat'],
                'payment_method' => strtoupper($data['metode_pembayaran']),
                'payment_status' => ServiceOrder::PAYMENT_UNPAID,
            ]);

            $order = app(JasaOrderBridgeService::class)->createLinkedOrder($serviceOrder, [
                'nama' => $data['nama'],
                'tel' => $data['tel'],
                'alamat' => $data['alamat'],
                'tanggal' => $data['tanggal'],
                'waktu' => $data['waktu'],
                'payment_method' => $data['metode_pembayaran'],
                'metode_pembayaran' => $data['metode_pembayaran'],
                'payment_status' => 'PENDING',
                'promo_code' => $data['promo_code'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'service_type' => $jasa->service_type ?? $jasa->service_type_booking ?? null,
                'service_type_booking' => $jasa->cara_pemesanan ?? null,
                'note' => $data['alamat'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Order berhasil dibuat.',
            'data' => $order?->fresh()->load(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation']),
            'service_order' => $serviceOrder?->fresh(['merchant', 'jasa']),
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
        return Order::with(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation'])->latest()->get();
    }

    /**
     * GET /api/admin/orders/{id}
     */
    public function adminShow(int $id)
    {
        $order = Order::with(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation'])->find($id);
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
        return Order::with(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation'])->latest()->get();
    }

    public function show($id)
    {
        $order = Order::with(['jasa', 'package', 'productItems.product', 'jasaItems.jasa', 'jasaItems.serviceOrder', 'jasaItems.serviceConsultation'])->findOrFail($id);
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
