<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\JasaOrderItem;
use App\Models\Jasa;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\ReviewMedia;
use App\Helpers\ApiResponse;
use App\Events\OrderStatusUpdated;
use App\Services\XenditInvoiceService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * JasaOrderController
 *
 * Controller baru untuk flow order jasa menggunakan Order + JasaOrderItem.
 * ServiceOrder sudah deprecated - menggunakan struktur ini sebagai gantinya.
 *
 * Flow baru:
 * 1. Customer checkout jasa
 * 2. Order dibuat (Order + JasaOrderItem)
 * 3. Untuk COD: langsung masuk 'menunggu_konfirmasi_merchant' + set confirm_deadline
 * 4. Untuk Xendit: masuk 'pending', setelah bayar baru ke 'menunggu_konfirmasi_merchant'
 */
class JasaOrderController extends Controller
{
    public function __construct(
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly WebPushService $webPushService
    ) {
    }

    /**
     * Create order jasa baru (langsung_pesan flow)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        $request->validate([
            'jasa_id' => 'required|exists:jasas,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_address' => 'nullable|string|max:500',
            'booking_date' => 'nullable|date',
            'booking_time' => 'nullable|date_format:H:i',
            'booking_note' => 'nullable|string|max:1000',
            'order_method' => 'nullable|string|in:keranjang,booking,konsultasi',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'total_price' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $jasa = Jasa::with(['merchant', 'images'])->findOrFail($request->jasa_id);

        // Validasi: hanya untuk UMKM Jasa
        if (!$jasa->merchant || $jasa->merchant->segmentation_id !== 3) {
            return ApiResponse::error('Layanan ini tidak tersedia untuk dipesan', 400);
        }

        // Validasi: hanya langsung_pesan atau booking
        $caraPemesanan = $jasa->cara_pemesanan ?? 'langsung_pesan';
        if (!in_array($caraPemesanan, ['langsung_pesan', 'booking'])) {
            return ApiResponse::error(
                'Layanan ini memerlukan konsultasi terlebih dahulu. Silakan gunakan fitur Ajukan Konsultasi.',
                400
            );
        }

        // ============================================================
        // VALIDASI DOUBLE BOOKING
        // Cek apakah sudah ada pesanan aktif pada tanggal & jam yang sama
        // Berlaku hanya untuk order_method = booking (dengan jadwal)
        // ============================================================
        $orderMethod = $request->order_method
            ?? JasaOrderItem::mapToOrderMethod($request->mekanisme_pemesanan)
            ?? JasaOrderItem::mapToOrderMethod($jasa->cara_pemesanan)
            ?? 'keranjang';

        if ($orderMethod === 'booking' && $request->booking_date && $request->booking_time) {
            $activeStatuses = [
                'menunggu_konfirmasi_merchant',
                'diterima',
                'layanan_dikerjakan',
                'menunggu_konfirmasi_selesai',
            ];

            $existingBooking = JasaOrderItem::where('jasa_id', $jasa->id)
                ->where('booking_date', $request->booking_date)
                ->where('booking_time', $request->booking_time)
                ->whereIn('order_method', ['booking', 'scheduled'])
                ->whereHas('order', function ($query) use ($activeStatuses) {
                    $query->whereIn('status', $activeStatuses);
                })
                ->first();

            if ($existingBooking) {
                return ApiResponse::error(
                    'Jadwal sudah terisi, silakan pilih jam lain.',
                    409
                );
            }
        }

        $customerId = Auth::id();
        $merchantId = $jasa->merchant_id;
        $serviceType = $jasa->service_type ?? null;

        // order_method mapping
        $orderMethod = $request->order_method
            ?? JasaOrderItem::mapToOrderMethod($request->mekanisme_pemesanan)
            ?? JasaOrderItem::mapToOrderMethod($jasa->cara_pemesanan)
            ?? 'keranjang';

        $paymentMethod = strtoupper($request->payment_method ?? 'COD');
        $isCodPayment = strtolower($paymentMethod) === 'cod';

        // Calculate total price
        $totalPrice = floatval($request->total_price ?? 0);
        if ($totalPrice <= 0) {
            $totalPrice = floatval($jasa->fixed_price ?? $jasa->base_price ?? $jasa->price ?? 0);
        }

        // Determine service location address
        $serviceLocationAddress = null;
        if ($serviceType === 'online') {
            $serviceLocationAddress = 'Online';
        } elseif ($serviceType === 'di_tempat_umjm' || $serviceType === 'at_location') {
            $serviceLocationAddress = $jasa->location_address ?? $jasa->merchant?->address ?? null;
        }

        $confirmMinutes = (int) config('app.order_confirm_minutes', 60);

        DB::beginTransaction();
        try {
            // Tentukan initial status berdasarkan payment method
            // SLA: all jasa orders start as menunggu_konfirmasi, merchant has 24h to respond
            $initialStatus = 'menunggu_konfirmasi';

            // Create order
            $customerName = $request->customer_name
                ?? Auth::user()?->name
                ?? Auth::user()?->nama
                ?? 'Customer';

            $order = Order::create([
                'user_id' => $customerId,
                'merchant_id' => $merchantId,
                'order_type' => 'jasa',
                'nama' => $customerName,
                'tel' => $request->customer_phone ?? '',
                'alamat' => $request->customer_address ?? '',
                'tanggal' => $request->booking_date ?? now()->toDateString(),
                'waktu' => $request->booking_time ?? '00:00',
                'total_price' => $totalPrice,
                'payment_method' => $paymentMethod,
                'payment_status' => 'UNPAID',
                'status' => $initialStatus,
                // SLA: merchant wajib merespon dalam durasi yang dikonfigurasi
                'merchant_response_deadline' => now()->addHours((int) config('sla.merchant_response_hours', 24)),
                // COD: langsung set confirm_deadline
                'confirm_deadline' => $isCodPayment ? now()->addMinutes($confirmMinutes) : null,
                // SNAPSHOT: Capture customer data at time of order
                'customer_name_snapshot' => $customerName,
                'customer_phone_snapshot' => $request->customer_phone ?? '',
                'customer_address_snapshot' => $request->customer_address ?? '',
                // SNAPSHOT: Capture merchant data at time of order
                'merchant_name_snapshot' => $jasa->merchant->name,
                'merchant_phone_snapshot' => $jasa->merchant->phone ?? '',
                'merchant_address_snapshot' => $jasa->merchant->address ?? '',
                // SNAPSHOT: Capture payment data at time of order
                'payment_method_snapshot' => $paymentMethod,
                'total_payment_snapshot' => $totalPrice,
            ]);

            // SNAPSHOT: Get jasa image URL
            $jasaImage = null;
            if ($jasa->coverImage) {
                $jasaImage = asset('storage/' . $jasa->coverImage->image_path);
            } elseif ($jasa->image) {
                $jasaImage = str_starts_with($jasa->image, 'http')
                    ? $jasa->image
                    : (str_starts_with($jasa->image, '/')
                        ? $jasa->image
                        : asset('storage/' . $jasa->image));
            }

            // Create jasa_order_items with SNAPSHOT data
            $jasaOrderItem = JasaOrderItem::create([
                'order_id' => $order->id,
                'jasa_id' => $jasa->id,
                'quantity' => 1,
                'price' => $totalPrice,
                'subtotal' => $totalPrice,
                'booking_date' => $request->booking_date,
                'booking_time' => $request->booking_time,
                'service_type' => $serviceType,
                'order_method' => $orderMethod,
                'note' => $request->booking_note,
                'booking_note' => $request->booking_note,
                'service_location_address' => $serviceLocationAddress,
                'customer_latitude' => $request->latitude,
                'customer_longitude' => $request->longitude,
                // SNAPSHOT: Capture jasa data at time of purchase
                'jasa_title_snapshot' => $jasa->title,
                'jasa_description_snapshot' => $jasa->description,
                'jasa_image_snapshot' => $jasaImage,
                'jasa_price_snapshot' => $totalPrice,
                'original_price_snapshot' => $jasa->base_price ?? $jasa->price ?? 0,
                'offered_price_snapshot' => $totalPrice,
                'agreed_price_snapshot' => $totalPrice,
                'service_type_snapshot' => $serviceType,
                // SNAPSHOT: Capture merchant data
                'merchant_name_snapshot' => $jasa->merchant->name,
                'merchant_phone_snapshot' => $jasa->merchant->phone,
            ]);

            DB::commit();

            Log::info('[JasaOrderController] Order created', [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'jasa_id' => $jasa->id,
                'payment_method' => $paymentMethod,
                'is_cod' => $isCodPayment,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            ]);

            // Build response
            $responseData = [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'payment_method' => $paymentMethod,
                'is_cod' => $isCodPayment,
                'status' => $order->status,
                'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            ];

            // COD: redirect URL
            if ($isCodPayment) {
                $redirectBase = config('app.url') . '/booking-confirmation';
                $responseData['redirect_url'] = "{$redirectBase}?order_id={$order->id}&status=pending";
            }

            return ApiResponse::success(
                $responseData,
                $isCodPayment ? 'Pesanan COD berhasil dibuat.' : 'Pesanan berhasil dibuat. Gunakan endpoint pembayaran untuk invoice.'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[JasaOrderController] Create error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal membuat pesanan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get customer jasa order history
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerHistory(Request $request)
    {
        $customerId = Auth::id();
        $perPage = $request->get('per_page', 20);

        $orders = Order::with([
            'merchant:id,name,slug,logo_path,segmentation_id',
            'jasaItems.jasa:id,title,image',
            'jasaItems.review',
            'jasaItems.completionEvidences',
            'payment',
        ])
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $orders->map(function ($order) {
            $jasaItem = $order->jasaItems->first();
            $payment = $order->payment;

            // Use snapshot data (prioritize snapshot over live data)
            $merchantName = $order->merchant_name_snapshot ?? $order->merchant?->name ?? 'Merchant';
            $serviceTitle = $jasaItem?->jasa_title_snapshot ?? $jasaItem?->jasa?->title ?? $jasaItem?->jasa?->name ?? 'Layanan';
            $serviceImage = $jasaItem?->jasa_image_snapshot ?? $jasaItem?->jasa?->image_url ?? null;
            $totalPrice = $order->total_payment_snapshot ?? $order->total_price ?? 0;
            $paymentMethod = $order->payment_method_snapshot ?? $order->payment_method ?? 'COD';

            // Build response - use BOTH id and order_id for FE compatibility
            // id: used by ServiceOrderCard/key binding; order_id: canonical name
            return [
                'id' => $order->id,
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'status' => $order->status,
                'order_status' => $order->status,
                'status_label' => $this->getServiceStatusLabel($order->status),
                'payment_status' => $order->payment_status,
                'payment_method' => $paymentMethod,
                'total_price' => $totalPrice,
                'merchant' => [
                    'id' => $order->merchant?->id,
                    'name' => $merchantName,
                    'slug' => $order->merchant?->slug,
                ],
                // Snapshot service data
                'service_title' => $serviceTitle,
                'service_image' => $serviceImage,
                // Payment info for "continue payment" button
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'invoice_url' => $payment->invoice_url,
                    'expired_at' => $payment->expired_at?->toISOString(),
                ] : null,
                'booking_date' => $jasaItem?->booking_date_snapshot ?? $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time_snapshot ?? $jasaItem?->booking_time,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
                // SLA timestamps
                'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
                'merchant_responded_at' => $order->merchant_responded_at?->toISOString(),
                'completion_submitted_at' => $order->completion_submitted_at?->toISOString(),
                'completion_deadline_at' => $order->completion_deadline_at?->toISOString(),
                'completed_at' => $order->completed_at?->toISOString(),
                'completed_by' => $order->completed_by,
                'auto_completed_at' => $order->auto_completed_at?->toISOString(),
                'cancelled_by' => $order->cancelled_by,
                'rejected_by' => $order->rejected_by,
                'expired_at' => $order->expired_at?->toISOString(),
                // Completion evidences — from jasaItems.completionEvidences
                'completion_evidences' => $jasaItem?->completionEvidences?->map(function ($ev) {
                    return [
                        'id' => $ev->id,
                        'jasa_order_item_id' => $ev->jasa_order_item_id,
                        'file_path' => $ev->file_path,
                        'file_url' => $ev->file_url,
                        'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                        'note' => $ev->note ?? null,
                        'created_at' => $ev->created_at?->toISOString(),
                    ];
                })?->toArray() ?? [],
                'completion_note' => $jasaItem?->completion_note ?? null,
                'created_at' => $order->created_at->toISOString(),
            ];
        });

        return ApiResponse::success($data, 'Orders fetched', 200, [
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail order jasa untuk customer
     *
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerShow(Request $request, int $orderId)
    {
        if (!$orderId || $orderId === 0) {
            return ApiResponse::error('ID pesanan tidak valid', 400);
        }

        $customerId = Auth::id();

        $order = Order::with([
            'user:id,name,phone',
            'merchant:id,name,slug,logo_path,phone,segmentation_id',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province',
            'merchant.primaryAddress.city',
            'merchant.primaryAddress.district',
            'merchant.primaryAddress.village',
            'jasaItems.jasa:id,title,image,description',
            'jasaItems.jasa.categories',
            'jasaItems.review',
            'jasaItems.completionEvidences',
        ])
            ->where('id', $orderId)
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $jasaItem = $order->jasaItems->first();

        // Use snapshot data (prioritize snapshot over live data)
        $merchantName = $order->merchant_name_snapshot ?? $order->merchant?->name ?? 'Merchant';
        $merchantPhone = $order->merchant_phone_snapshot ?? $order->merchant?->phone ?? null;
        $customerName = $order->customer_name_snapshot ?? $order->user?->name ?? '-';
        $customerPhone = $order->customer_phone_snapshot ?? $order->user?->phone ?? '-';
        $serviceTitle = $jasaItem?->jasa_title_snapshot ?? $jasaItem?->jasa?->title ?? $jasaItem?->jasa?->name ?? null;
        $serviceDescription = $jasaItem?->jasa_description_snapshot ?? $jasaItem?->jasa?->description ?? null;
        $serviceImage = $jasaItem?->jasa_image_snapshot ?? $jasaItem?->jasa?->image_url ?? null;
        $totalPrice = $order->total_payment_snapshot ?? $order->total_price ?? 0;
        $paymentMethod = $order->payment_method_snapshot ?? $order->payment_method ?? 'COD';
        $paymentChannel = $order->payment_channel_snapshot ?? $order->payment_channel ?? null;
        $bookingDate = $jasaItem?->booking_date_snapshot ?? $jasaItem?->booking_date ?? null;
        $bookingTime = $jasaItem?->booking_time_snapshot ?? $jasaItem?->booking_time ?? null;
        $customerNote = $jasaItem?->customer_note_snapshot ?? $jasaItem?->booking_note ?? null;
        $offerNote = $jasaItem?->offer_note_snapshot ?? null;
        $agreedAt = $jasaItem?->agreed_at?->toISOString() ?? null;
        $serviceType = $jasaItem?->service_type_snapshot ?? $jasaItem?->service_type ?? null;
        $serviceTypeLabel = $this->getServiceTypeLabel($serviceType);
        $orderMethod = $jasaItem?->order_method ?? null;
        $categoryName = $jasaItem?->jasa?->categories?->first()?->name ?? null;
        $merchantAddress = $this->getMerchantFullAddress($order);
        $serviceLocationAddress = $this->getServiceLocationAddress($serviceType, $order, $jasaItem);
        // Get payment info
        $payment = Payment::where('order_id', $orderId)->first();

        // Transform completion evidences from jasa_order_items relation
        $completionEvidences = collect();
        if ($jasaItem) {
            $jasaItem->load('completionEvidences');
            $completionEvidences = $jasaItem->completionEvidences->map(function ($ev) {
                return [
                    'id' => $ev->id,
                    'jasa_order_item_id' => $ev->jasa_order_item_id,
                    'file_path' => $ev->file_path,
                    'file_url' => $ev->file_url,
                    'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                    'note' => $ev->note ?? null,
                    'created_at' => $ev->created_at?->toISOString(),
                ];
            });
        }

        return ApiResponse::success([
            'id' => $order->id,
            'order_id' => $order->id,
            'order_number' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'jasa_order_item_id' => $jasaItem?->id,
            'status' => $order->status,
            'order_status' => $order->status,
            'status_label' => $this->getServiceStatusLabel($order->status),
            'cancelled_by' => $order->cancelled_by,
            'rejected_by' => $order->rejected_by,
            'rejection_reason' => $order->rejection_reason,
            'payment_status' => $order->payment_status,
            'payment_method' => $paymentMethod,
            'payment_channel' => $paymentChannel,
            'total_price' => $totalPrice,
            'paid_at' => $order->paid_at?->toISOString(),
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            'cancelled_at' => $order->cancelled_at?->toISOString(),
            // SLA timestamps
            'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
            'merchant_responded_at' => $order->merchant_responded_at?->toISOString(),
            'completion_submitted_at' => $order->completion_submitted_at?->toISOString(),
            'completion_deadline_at' => $order->completion_deadline_at?->toISOString(),
            'completed_at' => $order->completed_at?->toISOString(),
            'completed_by' => $order->completed_by,
            'auto_completed_at' => $order->auto_completed_at?->toISOString(),
            'expired_at' => $order->expired_at?->toISOString(),
            // Customer info
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            // Service info (flat + inside jasa_order_item)
            'service_name' => $serviceTitle,
            'category_name' => $categoryName,
            'service_type' => $serviceType,
            'service_type_label' => $serviceTypeLabel,
            'service_location_address' => $serviceLocationAddress,
            'service_image' => $serviceImage,
            // Booking
            'booking_date' => $bookingDate,
            'booking_time' => $bookingTime,
            // Cara pemesanan
            'cara_pemesanan' => $orderMethod,
            'cara_pemesanan_label' => $this->getOrderMethodLabel($orderMethod),
            // Merchant info
            'merchant' => [
                'id' => $order->merchant?->id,
                'name' => $merchantName,
                'phone' => $merchantPhone,
                'address' => $merchantAddress,
                'slug' => $order->merchant?->slug,
            ],
            'service_description' => $serviceDescription,
            'jasa_order_item' => [
                'id' => $jasaItem?->id,
                'service_type' => $serviceType,
                'service_type_label' => $serviceTypeLabel,
                'booking_date' => $bookingDate,
                'booking_time' => $bookingTime,
                'booking_note' => $customerNote,
                'offer_note' => $offerNote,
                'agreed_at' => $agreedAt,
                'service_location_address' => $serviceLocationAddress,
                'customer_confirmed' => $jasaItem?->customer_confirmed,
                'is_reviewed' => $jasaItem?->is_reviewed,
                'completion_evidences' => $completionEvidences->toArray(),
            ],
            'payment' => $payment ? [
                'id' => $payment->id,
                'status' => $payment->status,
                'invoice_url' => $payment->invoice_url,
                'expired_at' => $payment->expired_at?->toISOString(),
            ] : null,
            'created_at' => $order->created_at->toISOString(),
        ], 'Order detail fetched');
    }

    /**
     * Customer confirm order selesai
     *
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmCompleted(Request $request, int $orderId)
    {
        $customerId = Auth::id();

        $order = Order::with('jasaItems')
            ->where('id', $orderId)
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $jasaItem = $order->jasaItems->first();

        if (!$jasaItem) {
            return ApiResponse::error('Detail pesanan tidak ditemukan', 404);
        }

        if ($jasaItem->customer_confirmed) {
            return ApiResponse::error('Pesanan sudah dikonfirmasi sebelumnya', 400);
        }

        if ($order->status !== 'menunggu_konfirmasi_selesai') {
            return ApiResponse::error('Pesanan tidak dalam status menunggu konfirmasi selesai', 400);
        }

        DB::transaction(function () use ($order, $jasaItem) {
            $jasaItem->update([
                'customer_confirmed' => true,
                'customer_confirmed_at' => now(),
            ]);

            $order->update([
                'status' => 'selesai',
                'completed_at' => now(),
                'completed_by' => 'customer',
            ]);
        });

        event(new OrderStatusUpdated($order->fresh(), 'selesai'));

        Log::info('[JasaOrderController] Order completed', [
            'order_id' => $order->id,
        ]);

        return ApiResponse::success([
            'id' => $order->id,
            'order_id' => $order->id,
            'status' => 'selesai',
        ], 'Pesanan berhasil diselesaikan');
    }

    /**
     * Customer cancel jasa order
     *
     * Only allows cancellation when order is in waiting/pending state
     * (menunggu_konfirmasi_merchant).
     *
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelOrder(Request $request, int $orderId)
    {
        if (!$orderId || $orderId === 0) {
            return ApiResponse::error('ID pesanan tidak valid', 400);
        }

        $customerId = Auth::id();

        $order = Order::with('jasaItems')
            ->where('id', $orderId)
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Only allow cancel from cancellable statuses
        $cancellableStatuses = ['pending', 'menunggu_konfirmasi_merchant'];
        if (!in_array($order->status, $cancellableStatuses)) {
            return ApiResponse::error(
                "Pesanan dengan status '{$order->status}' tidak dapat dibatalkan.",
                422
            );
        }

        // Cancel any pending payment
        $payment = Payment::where('order_id', $orderId)
            ->whereIn('status', ['PENDING', 'UNPAID'])
            ->first();
        if ($payment) {
            $payment->update(['status' => 'EXPIRED']);
            event(new \App\Events\PaymentStatusUpdated($payment));
        }

        // Update order status to dibatalkan
        $order->update([
            'status' => 'dibatalkan',
            'cancelled_at' => now(),
            'cancelled_by' => 'customer',
        ]);

        // Update jasa_order_items status if exists
        $jasaItem = $order->jasaItems->first();
        if ($jasaItem) {
            $jasaItem->update(['status' => 'dibatalkan']);
        }

        Log::info('[JasaOrderController] Order cancelled', [
            'order_id' => $order->id,
            'previous_status' => $order->getOriginal('status'),
            'cancelled_by' => $customerId,
        ]);

        return ApiResponse::success([
            'id' => $order->id,
            'order_id' => $order->id,
            'status' => 'dibatalkan',
            'order_status' => 'dibatalkan',
            'status_label' => 'Dibatalkan',
            'cancelled_at' => $order->cancelled_at?->toISOString(),
        ], 'Pesanan berhasil dibatalkan.');
    }

    /**
     * Get human-readable label for service order status.
     */
    private function getServiceStatusLabel(string $status): string
    {
        $labels = [
            'menunggu_konfirmasi' => 'Menunggu Konfirmasi',
            'menunggu_konfirmasi_merchant' => 'Menunggu Konfirmasi',
            'pending' => 'Menunggu Konfirmasi',
            'diterima' => 'Diterima',
            'ditolak' => 'Ditolak Merchant',
            'layanan_dikerjakan' => 'Sedang Dikerjakan',
            'dikerjakan' => 'Sedang Dikerjakan',
            'processing' => 'Sedang Dikerjakan',
            'menunggu_konfirmasi_selesai' => 'Menunggu Konfirmasi Selesai',
            'menunggu_selesai' => 'Menunggu Konfirmasi Selesai',
            'selesai' => 'Selesai',
            'completed' => 'Selesai',
            'dibatalkan' => 'Dibatalkan',
            'cancelled' => 'Dibatalkan',
            'batal' => 'Dibatalkan',
            'expired' => 'Kadaluarsa',
        ];

        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Get service type label for API response.
     */
    private function getServiceTypeLabel(?string $serviceType): string
    {
        return match ($serviceType) {
            'online' => 'Online',
            'di_tempat_umkm', 'ditempat_umkm', 'at_location', 'at_merchant' => 'Di Tempat UMKM',
            'ke_rumah_pelanggan', 'ke_tempat_pelanggan', 'on_site', 'customer_location' => 'Ke Tempat Pelanggan',
            default => ucfirst($serviceType ?? '-'),
        };
    }

    /**
     * Get order method (cara pemesanan) label for API response.
     */
    private function getOrderMethodLabel(?string $orderMethod): string
    {
        return match ($orderMethod) {
            'keranjang', 'direct_checkout', 'checkout', 'langsung_pesan', 'direct' => 'Checkout Tanpa Jadwal',
            'booking', 'booking_schedule', 'scheduled' => 'Booking Jadwal',
            'konsultasi', 'consultation', 'memerlukan_konsultasi' => 'Hasil Konsultasi',
            default => ucfirst($orderMethod ?? '-'),
        };
    }

    /**
     * Get merchant full address from primaryAddress + province/city/district/village.
     */
    private function getMerchantFullAddress(Order $order): ?string
    {
        if (!empty($order->merchant_address_snapshot)) {
            return $order->merchant_address_snapshot;
        }
        $addr = $order->merchant?->primaryAddress;
        return $addr?->full_address;
    }

    /**
     * Get service location address based on service_type.
     */
    private function getServiceLocationAddress(?string $serviceType, Order $order, ?JasaOrderItem $jasaItem): ?string
    {
        $type = $serviceType ?? '';

        if ($type === 'online') {
            return 'Online';
        }

        if (in_array($type, ['di_tempat_umkm', 'ditempat_umkm', 'at_location', 'at_merchant'])) {
            return $this->getMerchantFullAddress($order);
        }

        if (in_array($type, ['ke_rumah_pelanggan', 'ke_tempat_pelanggan', 'on_site', 'customer_location'])) {
            return $order->customer_address_snapshot
                ?? $order->alamat
                ?? $jasaItem?->service_location_address
                ?? null;
        }

        return $this->getMerchantFullAddress($order);
    }

    /**
     * Get merchant jasa orders
     *
     * @param Request $request
     * @param string $merchantSlug
     * @return \Illuminate\Http\JsonResponse
     */
    public function merchantOrders(Request $request, string $merchantSlug)
    {
        $user = Auth::user();

        $merchant = \App\Models\Merchant::where('slug', $merchantSlug)
            ->where('user_id', $user->id)
            ->first();

        if (!$merchant) {
            return ApiResponse::error('Merchant tidak ditemukan', 404);
        }

        $perPage = $request->get('per_page', 20);
        $status = $request->get('status');

        // NOTE: Load jasaItems WITHOUT eager-loading jasa relation to avoid live data reads.
        // Use snapshot accessors instead: $jasaItem->jasa_title
        $query = Order::with(['jasaItems:id,order_id,jasa_id,jasa_title_snapshot'])
            ->where('merchant_id', $merchant->id)
            ->where('order_type', 'jasa');

        if ($status) {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('created_at')->paginate($perPage);

        $data = $orders->map(function ($order) {
            $jasaItem = $order->jasaItems->first();

            // Use snapshot accessors: jasa_title (snapshot > live)
            // Use customer_name (snapshot > user > nama)
            return [
                'id' => $order->id,
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'status' => $order->status,
                'order_status' => $order->status,
                'status_label' => $this->getServiceStatusLabel($order->status),
                'cancelled_by' => $order->cancelled_by,
                'rejected_by' => $order->rejected_by,
                'rejection_reason' => $order->rejection_reason,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_price' => $order->total_price,
                // Customer info dari SNAPSHOT
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                // Jasa info dari SNAPSHOT (jasa_title accessor: snapshot > live)
                'jasa' => [
                    'id' => $jasaItem?->jasa_id,
                    'title' => $jasaItem?->jasa_title,
                    'image' => $jasaItem?->jasa_image_snapshot,
                    'image_url' => $jasaItem?->jasa_image_url,
                ],
                // Booking info dari SNAPSHOT accessors
                'booking_date' => $jasaItem?->booking_date_snapshot ?? $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time_snapshot ?? $jasaItem?->booking_time,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
                // SLA timestamps
                'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
                'merchant_responded_at' => $order->merchant_responded_at?->toISOString(),
                'completion_submitted_at' => $order->completion_submitted_at?->toISOString(),
                'completion_deadline_at' => $order->completion_deadline_at?->toISOString(),
                'completed_at' => $order->completed_at?->toISOString(),
                'completed_by' => $order->completed_by,
                'auto_completed_at' => $order->auto_completed_at?->toISOString(),
                'expired_at' => $order->expired_at?->toISOString(),
                'has_evidence' => false, // completionEvidences not loaded
                'created_at' => $order->created_at?->toISOString(),
            ];
        });

        return ApiResponse::success($data, 'Orders fetched', 200, [
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Merchant update status order jasa
     *
     * @param Request $request
     * @param string $merchantSlug
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, string $merchantSlug, int $orderId)
    {
        $request->validate([
            'status' => 'required|in:diterima,ditolak,layanan_dikerjakan,menunggu_konfirmasi_selesai',
            'rejection_reason' => 'nullable|string|max:500',
            'completion_note' => 'nullable|string|max:1000',
            'evidences' => 'nullable|array|max:5',
            'evidences.*' => 'file|max:51200|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm',
        ]);

        $user = Auth::user();

        $merchant = \App\Models\Merchant::where('slug', $merchantSlug)
            ->where('user_id', $user->id)
            ->first();

        if (!$merchant) {
            return ApiResponse::error('Merchant tidak ditemukan', 404);
        }

        $order = Order::with('jasaItems')
            ->where('id', $orderId)
            ->where('merchant_id', $merchant->id)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $newStatus = $request->status;
        $validTransitions = $this->getValidTransitions($order->status);

        if (!in_array($newStatus, $validTransitions)) {
            return ApiResponse::error('Transisi status tidak valid', 422);
        }

        // SLA check: prevent accepting if deadline has passed
        if (in_array($newStatus, ['diterima']) && $order->merchant_response_deadline && $order->merchant_response_deadline->isPast()) {
            return ApiResponse::error('Batas waktu respons merchant sudah habis. Pesanan tidak dapat diterima.', 422);
        }

        DB::transaction(function () use ($order, $newStatus, $request) {
            $orderUpdate = ['status' => $newStatus];

            // Merchant responds (accept/reject) — set responded_at
            if (in_array($newStatus, ['diterima', 'ditolak'])) {
                $orderUpdate['merchant_responded_at'] = now();
            }

            // Store rejection reason in orders table
            if ($newStatus === 'ditolak') {
                $orderUpdate['rejected_at'] = now();
                $orderUpdate['rejected_by'] = 'merchant';
                $orderUpdate['rejection_reason'] = $request->rejection_reason;
            }

            $order->update($orderUpdate);

            $jasaItem = $order->jasaItems->first();
            if ($jasaItem && $newStatus === 'ditolak') {
                $jasaItem->update([
                    'completion_note' => $request->rejection_reason ?? 'Pesanan ditolak',
                ]);
            }

            // Handle completion evidence uploads when marking as menunggu_konfirmasi_selesai
            if ($newStatus === 'menunggu_konfirmasi_selesai' && $jasaItem) {
                // SLA: customer harus konfirmasi dalam durasi yang dikonfigurasi
                $order->update([
                    'completion_submitted_at' => now(),
                    'completion_deadline_at' => now()->addHours((int) config('sla.customer_confirm_hours', 24)),
                ]);
                $files = $request->file('evidences', []);
                foreach ($files as $index => $file) {
                    $error = \App\Models\ServiceCompletionEvidence::validateFile($file);
                    if ($error) {
                        throw new \Exception("File {$file->getClientOriginalName()}: {$error}");
                    }

                    $type = \App\Models\ServiceCompletionEvidence::getFileType($file->getMimeType());
                    $path = \App\Models\ServiceCompletionEvidence::generatePath(
                        $file->getClientOriginalName(),
                        $type === 'image' ? 'images' : 'videos'
                    );

                    \Illuminate\Support\Facades\Storage::disk('public')->put($path, file_get_contents($file));

                    \App\Models\ServiceCompletionEvidence::create([
                        'jasa_order_item_id' => $jasaItem->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        // file_url generated by accessor from file_path
                        'file_type' => $type,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'display_order' => $index,
                        'note' => $request->completion_note ?? null,
                    ]);
                }

                if ($request->filled('completion_note')) {
                    $jasaItem->update(['completion_note' => $request->completion_note]);
                }
            }
        });

        event(new OrderStatusUpdated($order->fresh(), $newStatus));

        Log::info('[JasaOrderController] Status updated', [
            'order_id' => $order->id,
            'new_status' => $newStatus,
        ]);

        return ApiResponse::success([
            'id' => $order->id,
            'order_id' => $order->id,
            'status' => $newStatus,
        ], 'Status pesanan berhasil diupdate');
    }

    /**
     * Customer submit review for completed jasa order
     *
     * @param Request $request
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitReview(Request $request, int $orderId)
    {
        // Validate request fields
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:80',
            'comment' => 'required|string|min:10|max:500',
            'is_anonymous' => 'nullable',
            'media' => 'nullable',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:10240',
        ]);

        $customerId = Auth::id();

        // Find order — customer must own it
        $order = Order::with(['merchant', 'jasaItems.jasa'])
            ->where('id', $orderId)
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Get jasa_order_item
        $jasaItem = $order->jasaItems->first();

        if (!$jasaItem) {
            return ApiResponse::error('Detail pesanan tidak ditemukan', 404);
        }

        $jasaId = $jasaItem->jasa_id;
        $merchantId = $order->merchant_id;
        $jasa = $jasaItem->jasa;

        // VALIDATION: Order must be 'selesai'
        if ($order->status !== 'selesai') {
            return ApiResponse::error(
                'Pesanan harus selesai terlebih dahulu sebelum memberikan review',
                400
            );
        }

        // VALIDATION: Not already reviewed
        $existingReview = Rating::where('jasa_order_item_id', $jasaItem->id)
            ->where('user_id', $customerId)
            ->first();

        if ($existingReview) {
            return ApiResponse::error('Anda sudah memberikan review untuk pesanan ini', 409);
        }

        try {
            DB::beginTransaction();

            // Handle is_anonymous from FormData (string '0'/'1' or boolean)
            $isAnonymous = false;
            if (isset($data['is_anonymous'])) {
                $val = $data['is_anonymous'];
                if (is_bool($val)) {
                    $isAnonymous = $val;
                } elseif (is_string($val)) {
                    $isAnonymous = in_array(strtolower($val), ['1', 'true', 'yes']);
                } else {
                    $isAnonymous = (bool) $val;
                }
            }

            // Create rating for the jasa
            $ratingData = [
                'user_id' => $customerId,
                'merchant_id' => $merchantId,
                'jasa_order_item_id' => $jasaItem->id,
                'rateable_id' => $jasaId,
                'rateable_type' => Jasa::class,
                'rating' => (int) $data['rating'],
                'title' => $data['title'] ?? null,
                'comment' => $data['comment'] ?? null,
                'is_anonymous' => $isAnonymous,
            ];

            Log::info('[JasaOrderController] submitReview - Creating rating:', $ratingData);

            $review = Rating::create($ratingData);

            Log::info('[JasaOrderController] submitReview - Rating created:', ['id' => $review->id]);

            // Handle media uploads
            if ($request->hasFile('media')) {
                $mediaFiles = $request->file('media');

                if (!is_array($mediaFiles) && $mediaFiles instanceof \Illuminate\Http\UploadedFile) {
                    $mediaFiles = [$mediaFiles];
                }

                Log::info('[JasaOrderController] submitReview - Processing media files:', [
                    'count' => count($mediaFiles),
                ]);

                $basePath = 'reviews';
                if (!Storage::disk('public')->exists($basePath)) {
                    Storage::disk('public')->makeDirectory($basePath);
                }

                foreach ($mediaFiles as $index => $file) {
                    try {
                        $extension = $file->getClientOriginalExtension();
                        $mimeType = $file->getMimeType();
                        $isImage = str_starts_with($mimeType, 'image/');
                        $type = $isImage ? 'images' : 'videos';
                        $dateFolder = now()->format('Y/m/d');
                        $newFileName = uniqid() . '_' . time() . '_' . $index . '.' . $extension;

                        $file->storeAs("{$basePath}/{$type}/{$dateFolder}", $newFileName, ['disk' => 'public']);

                        $storedPath = "{$basePath}/{$type}/{$dateFolder}/{$newFileName}";

                        ReviewMedia::create([
                            'review_id' => $review->id,
                            'file_path' => $storedPath,
                            'file_url' => Storage::url($storedPath),
                            'file_type' => $isImage ? 'image' : 'video',
                            'mime_type' => $mimeType,
                            'original_name' => $file->getClientOriginalName(),
                            'file_size' => $file->getSize(),
                            'display_order' => $index,
                        ]);

                        Log::info('[JasaOrderController] submitReview - Media saved:', [
                            'index' => $index,
                            'path' => $storedPath,
                        ]);
                    } catch (\Exception $mediaException) {
                        Log::error('[JasaOrderController] submitReview - Media save failed:', [
                            'index' => $index,
                            'error' => $mediaException->getMessage(),
                        ]);
                    }
                }
            }

            // Mark jasa_order_item as reviewed
            $jasaItem->update([
                'is_reviewed' => true,
                'review_id' => $review->id,
            ]);

            DB::commit();

            // Load relationships for response
            $review->load(['user', 'media']);

            Log::info('[JasaOrderController] submitReview - Success:', ['review_id' => $review->id]);

            // Build FE-compatible response
            $responseData = [
                'id' => $order->id, // PRIMARY ID
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem->id,
                'rating' => $review->rating,
                'review' => $review,
                'media' => $review->media ?? [],
            ];

            return ApiResponse::success($responseData, 'Review berhasil dikirim. Terima kasih atas ulasan Anda!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[JasaOrderController] submitReview - Error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponse::error('Gagal mengirim review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get valid status transitions
     */
    private function getValidTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            'menunggu_konfirmasi' => ['diterima', 'ditolak'],
            'menunggu_konfirmasi_merchant' => ['diterima', 'ditolak'], // backward compat
            'diterima' => ['layanan_dikerjakan'],
            'layanan_dikerjakan' => ['menunggu_konfirmasi_selesai', 'selesai'],
            'menunggu_konfirmasi_selesai' => ['selesai'],
            default => [],
        };
    }
}