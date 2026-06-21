<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Order;
use App\Models\Jasa;
use App\Models\Package;
use App\Models\Promo;
use App\Models\Merchant;
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
     * NOTE: Access jasas through jasaItems.jasa
     */
    public function myOrders(Request $request)
    {
        return Order::with(['package', 'productItems.product', 'jasaItems.jasa'])
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
        $order = Order::with(['package', 'productItems.product', 'jasaItems.jasa'])->find($id);
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
     *
     * DEPRECATED: Gunakan JasaOrderController::create() sebagai gantinya.
     * Method ini dipertahankan untuk backward compatibility.
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

        $jasa = Jasa::with('merchant')->findOrFail($data['jasa_id']);
        $userId = $request->user()->id;
        $paymentMethod = strtoupper($data['metode_pembayaran']);
        $isCodPayment = strtolower($paymentMethod) === 'cod';
        $confirmMinutes = (int) config('app.order_confirm_minutes', 60);

        // Tentukan initial status
        // COD: langsung tunggu konfirmasi merchant
        // Xendit: tunggu pembayaran dulu
        $initialStatus = $isCodPayment ? 'menunggu_konfirmasi_merchant' : 'pending';

        $order = DB::transaction(function () use ($jasa, $userId, $data, $paymentMethod, $initialStatus, $confirmMinutes) {
            // Create Order
            $order = Order::create([
                'user_id' => $userId,
                'merchant_id' => $jasa->merchant_id,
                'order_type' => 'jasa',
                'nama' => $data['nama'],
                'tel' => $data['tel'],
                'alamat' => $data['alamat'],
                'tanggal' => $data['tanggal'],
                'waktu' => $data['waktu'],
                'total_price' => $data['total'],
                'payment_method' => $paymentMethod,
                'payment_status' => 'UNPAID',
                'status' => $initialStatus,
                // COD: langsung set confirm_deadline
                'confirm_deadline' => $isCodPayment ? now()->addMinutes($confirmMinutes) : null,
            ]);

            // Create JasaOrderItem
            $jasaOrderItem = JasaOrderItem::create([
                'order_id' => $order->id,
                'jasa_id' => $jasa->id,
                'quantity' => 1,
                'price' => $data['total'],
                'subtotal' => $data['total'],
                'booking_date' => $data['tanggal'],
                'booking_time' => $data['waktu'],
                'service_type' => $jasa->service_type,
                'order_method' => 'keranjang',
            ]);

            return $order;
        });

        // Load relasi untuk response
        $order->load(['merchant', 'jasaItems.jasa']);

        return response()->json([
            'message' => 'Order berhasil dibuat.',
            'data' => [
                'order_id' => $order->id,
                'jasa_order_item_id' => $order->jasaItems->first()?->id,
                'status' => $order->status,
                'payment_method' => $paymentMethod,
                'is_cod' => $isCodPayment,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            ],
            'order' => $order,
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
        return Order::with(['package', 'productItems.product', 'jasaItems.jasa'])->latest()->get();
    }

    /**
     * GET /api/admin/orders/{id}
     */
    public function adminShow(int $id)
    {
        $order = Order::with(['package', 'productItems.product', 'jasaItems.jasa'])->find($id);
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
        return Order::with(['package', 'productItems.product', 'jasaItems.jasa'])->latest()->get();
    }

    public function show($id)
    {
        $order = Order::with(['package', 'productItems.product', 'jasaItems.jasa'])->findOrFail($id);
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
