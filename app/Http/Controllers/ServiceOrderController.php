<?php

namespace App\Http\Controllers;

use App\Models\ServiceCompletionEvidence;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\JasaOrderItem;
use App\Models\Rating;
use App\Models\RatingSummary;
use App\Models\ReviewHistory;
use App\Models\ReviewMedia;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * ServiceOrderController
 *
 * Service order lifecycle using NEW architecture:
 * - orders = tabel utama pesanan (PRIMARY)
 * - jasa_order_items = detail layanan jasa (PRIMARY)
 * - service_orders = backward compatibility ONLY (legacy orders)
 *
 * NEW ORDERS: No ServiceOrder is created. All data in orders + jasa_order_items.
 * OLD ORDERS: Found via service_order_id in jasa_order_items (legacy).
 *
 * Status values: Uses service status values directly (no more generic: pending/proses/batal)
 * - menunggu_konfirmasi_merchant
 * - diterima
 * - ditolak
 * - layanan_dikerjakan
 * - menunggu_konfirmasi_selesai
 * - selesai
 * - dibatalkan
 */
class ServiceOrderController extends Controller
{
    // Status constants (source of truth: orders.status)
    private const STATUS_DITERIMA = 'diterima';
    private const STATUS_DITOLAK = 'ditolak';
    private const STATUS_DIKERJAKAN = 'layanan_dikerjakan';
    private const STATUS_MENUNGGU_SELESAI = 'menunggu_konfirmasi_selesai';
    private const STATUS_SELESAI = 'selesai';

    /**
     * Create a new service order (langsung_pesan flow)
     * Creates order using Order + JasaOrderItem (NO ServiceOrder anymore)
     * Service-specific details are stored in jasa_order_items table
     *
     * NEW ARCHITECTURE:
     * - orders = PRIMARY table for all order data
     * - jasa_order_items = PRIMARY table for service details
     * - service_orders = NOT created for new orders (backward compat only)
     *
     * Initial status: menunggu_konfirmasi_merchant (for COD)
     * Status values use service statuses directly (not generic: pending/proses/batal)
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
            // order_method: mekanisme pemesanan (FE format: keranjang, booking, konsultasi)
            'order_method' => 'nullable|string|in:keranjang,booking,konsultasi',
            // Legacy aliases (still accepted for backward compat)
            'mekanisme_pemesanan' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'total_price' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $jasa = Jasa::with(['merchant.primaryAddress', 'images'])->findOrFail($request->jasa_id);

        // VALIDATION: Only allow orders for UMKM Jasa
        if (!$jasa->merchant || $jasa->merchant->segmentation_id !== 3) {
            return ApiResponse::error('Layanan ini tidak tersedia untuk dipesan', 400);
        }

        // VALIDATION: Only allow langsung_pesan or booking services
        $caraPemesanan = $jasa->cara_pemesanan ?? 'langsung_pesan';
        if (!in_array($caraPemesanan, ['langsung_pesan', 'booking'])) {
            return ApiResponse::error(
                'Layanan ini memerlukan konsultasi terlebih dahulu. Silakan gunakan fitur Ajukan Konsultasi.',
                400
            );
        }

        // order_method: mekanisme pemesanan (PRIMARY - FE format: keranjang, booking, konsultasi)
        // Mapping dari berbagai input legacy:
        // - order_method: keranjang, booking, konsultasi
        // - cara_pemesanan: langsung_pesan, booking, memerlukan_konsultasi (DB format)
        // - mekanisme_pemesanan: keranjang, booking, konsultasi
        // Fallback: ambil dari jasa.cara_pemesanan (DB format)
        $orderMethod = $request->order_method
            ?? JasaOrderItem::mapToOrderMethod($request->cara_pemesanan)
            ?? JasaOrderItem::mapToOrderMethod($request->mekanisme_pemesanan)
            ?? JasaOrderItem::mapToOrderMethod($jasa->cara_pemesanan); // fallback dari DB
        $orderMethod = $orderMethod ?: 'keranjang'; // Default FE format

        // ============================================================
        // VALIDASI DOUBLE BOOKING
        // Cek apakah sudah ada pesanan aktif pada tanggal & jam yang sama
        // Berlaku hanya untuk order_method = booking (dengan jadwal)
        // ============================================================
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

        // order_method: mekanisme pemesanan (PRIMARY - FE format: keranjang, booking, konsultasi)
        // Mapping dari berbagai input legacy:
        // - order_method: keranjang, booking, konsultasi
        // - cara_pemesanan: langsung_pesan, booking, memerlukan_konsultasi (DB format)
        // - mekanisme_pemesanan: keranjang, booking, konsultasi
        // Fallback: ambil dari jasa.cara_pemesanan (DB format)
        $orderMethod = $request->order_method
            ?? JasaOrderItem::mapToOrderMethod($request->cara_pemesanan)
            ?? JasaOrderItem::mapToOrderMethod($request->mekanisme_pemesanan)
            ?? JasaOrderItem::mapToOrderMethod($jasa->cara_pemesanan); // fallback dari DB
        $orderMethod = $orderMethod ?: 'keranjang'; // Default FE format

        $paymentMethod = strtoupper($request->payment_method ?? 'COD');
        $isCodPayment = strtolower($paymentMethod) === 'cod';

        // DEBUG: Log payment method yang diterima dari frontend
        Log::info('[ServiceOrder Create] Payment method received', [
            'raw_payment_method' => $request->payment_method,
            'uppercased' => $paymentMethod,
            'is_cod' => $isCodPayment,
            'order_method' => $orderMethod,
            'legacy_mekanisme' => $request->mekanisme_pemesanan,
        ]);

        // Calculate total price
        $totalPrice = floatval($request->total_price ?? 0);
        if ($totalPrice <= 0) {
            $totalPrice = floatval($jasa->fixed_price ?? $jasa->base_price ?? $jasa->price ?? 0);
        }

        // Get service image URL
        $serviceImage = $jasa->coverImage
            ? asset('storage/' . $jasa->coverImage->image_path)
            : ($jasa->image ? asset('storage/' . $jasa->image) : null);

        // Determine service location address based on service type
        $serviceLocationAddress = null;
        if ($serviceType === 'online') {
            $serviceLocationAddress = 'Online';
        } elseif ($serviceType === 'di_tempat_umkm' || $serviceType === 'at_location') {
            $serviceLocationAddress = $jasa->location_address ?? $jasa->merchant?->address ?? null;
        }

        DB::beginTransaction();
        try {
            // ===== 1. Create order in orders table (PRIMARY) =====
            // NOTE: order_type = 'jasa' (jenis order utama: product/jasa)
            // order_method disimpan di jasa_order_items, BUKAN di orders
            // Status uses service status values directly (NOT generic: pending/proses/batal)
            $customerName = $request->customer_name
                ?? Auth::user()?->name
                ?? Auth::user()?->nama
                ?? 'Customer';
            $order = Order::create([
                'user_id' => $customerId,
                'merchant_id' => $merchantId,
                'order_type' => 'jasa', // All service orders are type 'jasa'
                'nama' => $customerName,
                'tel' => $request->customer_phone ?? '',
                'alamat' => $request->customer_address ?? '',
                'tanggal' => $request->booking_date ?? now()->toDateString(),
                'waktu' => $request->booking_time ?? '00:00',
                'total_price' => $totalPrice,
                'payment_method' => $paymentMethod,
                'payment_status' => 'UNPAID',
                // NEW ARCHITECTURE: Use service status value directly
                'status' => 'menunggu_konfirmasi_merchant',
                // SNAPSHOT: Capture customer data at time of order
                'customer_name_snapshot' => $customerName,
                'customer_phone_snapshot' => $request->customer_phone ?? '',
                'customer_address_snapshot' => $request->customer_address ?? '',
                // SNAPSHOT: Capture merchant data at time of order
                'merchant_name_snapshot' => $merchant->name,
                'merchant_phone_snapshot' => $merchant->phone ?? '',
                'merchant_address_snapshot' => $merchant->address ?? '',
                // SNAPSHOT: Capture payment data at time of order
                'payment_method_snapshot' => $paymentMethod,
                'total_payment_snapshot' => $totalPrice,
            ]);

            // ===== 2. Create jasa_order_items (service-specific details - PRIMARY) =====
            // order_method: mekanisme pemesanan (keranjang, booking, konsultasi)
            // SNAPSHOT: Capture jasa data at time of order
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

            $jasaOrderItem = JasaOrderItem::create([
                'order_id' => $order->id,
                'jasa_id' => $jasa->id,
                'quantity' => 1,
                'price' => $totalPrice,
                'subtotal' => $totalPrice,
                'booking_date' => $request->booking_date,
                'booking_time' => $request->booking_time,
                'service_type' => $serviceType,
                'order_method' => $orderMethod, // PRIMARY: keranjang | booking | konsultasi
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
                'merchant_name_snapshot' => $merchant->name,
                'merchant_phone_snapshot' => $merchant->phone,
                // NO service_order_id for new orders (backward compat only)
            ]);

            // NOTE: NO ServiceOrder::create() for new orders
            // ServiceOrders are only for backward compatibility with legacy data

            DB::commit();

            Log::info('[ServiceOrder Create] Order created (NEW architecture)', [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'jasa_id' => $jasa->id,
                'merchant_id' => $merchantId,
                'payment_method' => $paymentMethod,
                'is_cod' => $isCodPayment,
                'status' => $order->status,
            ]);

            // Build response
            // For Xendit: Frontend should call POST /api/payments/{order_id}/invoice to create Xendit invoice
            // For COD: Redirect to booking confirmation
            $responseData = [
                'success' => true,
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'status' => $order->status,
                'payment_method' => $paymentMethod,
                'is_cod' => $isCodPayment,
                // Frontend should call POST /api/payments/{order_id}/invoice for Xendit
            ];

            // COD: include redirect_url for frontend convenience
            if ($isCodPayment) {
                $redirectBase = config('app.url') . '/booking-confirmation';
                $queryParams = http_build_query([
                    'order_id' => $order->id,
                    'status' => 'pending',
                ]);
                $responseData['redirect_url'] = "{$redirectBase}?{$queryParams}";
                Log::info('[ServiceOrder Create] COD redirect built', [
                    'order_id' => $order->id,
                    'redirect_url' => $responseData['redirect_url'],
                ]);
            }

            Log::info('[ServiceOrder Create] Final response built', [
                'order_id' => $responseData['order_id'],
                'payment_method' => $responseData['payment_method'],
                'is_cod' => $isCodPayment,
                'has_redirect_url' => !empty($responseData['redirect_url']),
            ]);

            return ApiResponse::success($responseData, $isCodPayment ? 'Pesanan COD berhasil dibuat.' : 'Pesanan berhasil dibuat. Gunakan endpoint pembayaran untuk invoice.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ServiceOrder Create] Error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal membuat pesanan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get customer service order history
     *
     * NEW ARCHITECTURE: Uses orders as primary source, jasa_order_items for service details.
     * NO service_orders dependency.
     */
    public function getCustomerHistory(Request $request)
    {
        $customerId = Auth::id();
        $perPage = $request->get('per_page', 100);

        // Query from orders table + jasa_order_items (NEW ARCHITECTURE)
        // NOTE: Load jasaItems WITHOUT eager-loading jasa relation to avoid live data reads.
        // Use snapshot accessors instead: $jasaItem->jasa_title, $jasaItem->jasa_image_url
        $query = Order::with([
            'merchant:id,name,slug,logo_path,segmentation_id',
            'jasaItems:id,order_id,jasa_id,jasa_title_snapshot,jasa_image_snapshot,booking_date,booking_time,booking_note,service_type,order_method,service_location_address,completion_note,customer_latitude,customer_longitude,is_reviewed,review_id,customer_confirmed,customer_confirmed_at',
            'jasaItems.review.media',
            'jasaItems.review.histories',
            'jasaItems.completionEvidences',
            'jasaItems',
        ])
            ->where('user_id', $customerId)
            ->whereHas('jasaItems')
            ->orderByDesc('created_at');

        $orders = $query->paginate($perPage);

        $transformedData = collect($orders->items())->map(function ($order) {
            $jasaItem = $order->jasaItems->first();

            $orderArray = [];

            // IDs
            $orderArray['id'] = $order->id;
            $orderArray['order_id'] = $order->id;
            $orderArray['jasa_order_item_id'] = $jasaItem?->id;

            // Get jasa_order_item for additional data
            if ($jasaItem) {
                $orderArray['service_type'] = $jasaItem->service_type;
                $orderArray['service_type_label'] = $jasaItem->service_type_label;
                $orderArray['booking_type'] = $jasaItem->order_method;
                $orderArray['booking_date'] = $jasaItem->booking_date;
                $orderArray['booking_time'] = $jasaItem->booking_time;
                $orderArray['tanggal'] = $jasaItem->booking_date ?? $order->tanggal;
                $orderArray['waktu'] = $jasaItem->booking_time ?? $order->waktu;
                $orderArray['booking_note'] = $jasaItem->booking_note ?? $jasaItem->note;
                $orderArray['service_location_address'] = $jasaItem->service_location_address;
            }

            // STATUS - from orders table
            $orderArray['order_status'] = $order->status;
            $orderArray['order_status_label'] = $this->getServiceStatusLabel($order->status);
            $orderArray['status'] = $order->status;
            $orderArray['status_label'] = $this->getServiceStatusLabel($order->status);

            // Order Number
            $orderArray['order_number'] = 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);

            // Customer Info - gunakan SNAPSHOT accessor
            $orderArray['customer_name'] = $order->customer_name; // snapshot > user > nama
            $orderArray['customer_phone'] = $order->customer_phone; // snapshot > user > tel
            $orderArray['customer_address'] = $jasaItem?->service_location_address;

            // Payment Info
            $orderArray['payment_status'] = $order->payment_status;
            $orderArray['payment_method'] = $order->payment_method;
            $orderArray['payment_channel'] = $order->payment_channel ?? $order->paid_channel;
            $orderArray['paid_channel'] = $order->paid_channel;
            $orderArray['is_payment_completed'] = strtoupper($order->payment_status ?? '') === 'PAID' || strtoupper($order->payment_method ?? '') === 'COD';

            // Service Info - gunakan SNAPSHOT accessor (jasa_title, jasa_image_url)
            $orderArray['total_price'] = (float) $order->total_price;
            $orderArray['service_name'] = $jasaItem?->jasa_title; // snapshot > live data
            $orderArray['service_image'] = $jasaItem?->jasa_image_url; // snapshot > live data

            // Order Method
            $orderArray['order_method'] = $jasaItem?->order_method;
            $orderArray['order_method_label'] = $jasaItem?->order_method_label;
            $orderArray['order_type'] = $order->order_type;

            // Review Data
            $review = $jasaItem?->review;
            $isReviewed = $jasaItem?->is_reviewed === true;

            if ($review) {
                $reviewMedia = [];
                if ($review->media) {
                    $reviewMedia = $review->media->map(function ($media) {
                        $arr = $media->toArray();
                        $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                        return $arr;
                    })->toArray();
                }
                $orderArray['review'] = array_merge($review->toArray(), ['media' => $reviewMedia]);
            } else {
                $orderArray['review'] = null;
            }
            $orderArray['is_reviewed'] = $isReviewed;

            // Completion Evidences
            $completionEvidences = $jasaItem?->completionEvidences ?? collect();
            $orderArray['completion_note'] = $jasaItem?->completion_note;
            $orderArray['completion_evidences'] = $completionEvidences->map(function ($evidence) {
                return [
                    'id' => $evidence->id,
                    'jasa_order_item_id' => $evidence->jasa_order_item_id,
                    'file_path' => $evidence->file_path,
                    'file_url' => $evidence->file_url,
                    'image_url' => $evidence->file_url,
                    'url' => $evidence->file_url,
                    'file_type' => $evidence->file_type ?? ($evidence->is_video ? 'video' : 'image'),
                    'note' => $evidence->note ?? null,
                    'created_at' => $evidence->created_at?->toIso8601String(),
                ];
            })->toArray();

            // Merchant Info - gunakan SNAPSHOT accessor
            $orderArray['merchant'] = [
                'id' => $order->merchant_id,
                'name' => $order->merchant_name, // snapshot > live
                'slug' => $order->merchant?->slug,
            ];

            // Jasa Info - gunakan SNAPSHOT accessor
            $orderArray['jasa'] = [
                'id' => $jasaItem?->jasa_id,
                'title' => $jasaItem?->jasa_title, // snapshot > live
                'image' => $jasaItem?->jasa_image_snapshot, // snapshot raw
                'image_url' => $jasaItem?->jasa_image_url, // snapshot with asset() helper
                'slug' => $jasaItem?->jasa?->slug,
            ];

            // Timestamps
            $orderArray['created_at'] = $order->created_at?->toIso8601String();
            $orderArray['updated_at'] = $order->updated_at?->toIso8601String();

            return $orderArray;
        })->toArray();

        return ApiResponse::success(['data' => $transformedData], 'success');
    }

    public function getCustomerOrderDetail(Request $request, int $id)
    {
        $customerId = Auth::id();

        try {
            // Find order in orders table
            // NOTE: 'address' is NOT a DB column on merchants.
            // Must eager-load primaryAddress relationship.
            // NOTE: Load jasaItems WITHOUT eager-loading jasa relation to avoid live data reads.
            // Use snapshot accessors instead: $jasaItem->jasa_title, $jasaItem->jasa_image_url
            $order = Order::with([
                'merchant:id,name,slug,logo_path,segmentation_id,phone',
                'merchant.primaryAddress',
                'payment',
                'jasaItems:id,order_id,jasa_id,jasa_title_snapshot,jasa_image_snapshot,booking_date,booking_time,booking_note,service_type,order_method,service_location_address,completion_note,customer_latitude,customer_longitude,is_reviewed,review_id,customer_confirmed,customer_confirmed_at',
                'jasaItems.review.media',
                'jasaItems.review.histories',
                'jasaItems.completionEvidences',
            ])
                ->where('user_id', $customerId)
                ->whereHas('jasaItems')
                ->find($id);

            if (!$order) {
                return ApiResponse::error('Pesanan tidak ditemukan', 404);
            }

            $jasaItem = $order->jasaItems->first();
            $transformedOrder = $this->transformOrderForCustomerOnly($order, $jasaItem);

            return ApiResponse::success($transformedOrder, 'success');
        } catch (\Exception $e) {
            Log::error('[ServiceOrderController] getCustomerOrderDetail failed', [
                'id' => $id,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Gagal memuat detail pesanan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Transform order for customer-facing responses (NEW ARCHITECTURE)
     */
    private function transformOrderForCustomerOnly(Order $order, ?JasaOrderItem $jasaItem): array
    {
        $orderArray = $order->toArray();

        // Get merchant address
        $primaryAddress = $order->merchant?->primaryAddress;
        $merchantAddress = $primaryAddress?->detail ?? null;

        // Determine display address based on service type
        $serviceType = $jasaItem?->service_type;
        $displayAddress = match ($serviceType) {
            'online' => 'Online',
            'di_tempat_umkm', 'at_location' => $merchantAddress ?? 'Lokasi UMKM',
            default => $order->alamat ?? $merchantAddress ?? '-',
        };

        // Status
        $status = $order->status ?? 'menunggu_konfirmasi_merchant';
        $statusLabel = $this->getServiceStatusLabel($status);

        // Payment Info
        $paymentStatus = $order->payment_status;
        $paymentMethod = $order->payment_method;
        $paymentChannel = $order->payment_channel ?? $order->paid_channel;
        $totalPrice = (float) $order->total_price;

        // Payment relation (Xendit)
        $paymentRelation = $order->payment;
        if ($paymentRelation) {
            if ($paymentRelation->status === 'PAID') {
                $paymentStatus = 'PAID';
            }
            if ($paymentRelation->paid_channel) {
                $paymentChannel = $paymentRelation->paid_channel;
            }
        }

        $paymentMethodLabels = [
            'cod' => 'Bayar di Tempat (COD)',
            'COD' => 'Bayar di Tempat (COD)',
            'ONLINE_XENDIT' => 'Online (Xendit)',
            'QRIS' => 'QRIS',
            'BCA_VA' => 'BCA Virtual Account',
            'BNI_VA' => 'BNI Virtual Account',
            'BRI_VA' => 'BRI Virtual Account',
            'MANDIRI_VA' => 'Mandiri Virtual Account',
            'OVO' => 'OVO',
            'DANA' => 'DANA',
            'SHOPEEPAY' => 'ShopeePay',
            'ALFAMART' => 'Alfamart / Alfamidi',
        ];
        $paymentMethodDisplay = $paymentMethodLabels[strtoupper($paymentMethod ?? '')]
            ?? $paymentMethodLabels[strtolower($paymentMethod ?? '')]
            ?? $paymentMethod ?? '-';

        $paymentStatusLabels = [
            'UNPAID' => 'Belum Bayar',
            'PAID' => 'Lunas / Sudah Dibayar',
            'WAITING_CONFIRMATION' => 'Menunggu Konfirmasi',
            'PENDING' => 'Menunggu Pembayaran',
        ];
        $paymentStatusDisplay = $paymentStatusLabels[strtoupper($paymentStatus ?? '')] ?? $paymentStatus ?? '-';

        $isPaymentCompleted = strtoupper($paymentMethod ?? '') === 'COD'
            || strtoupper($paymentStatus ?? '') === 'PAID';

        // Review
        $review = $jasaItem?->review;
        $isReviewed = $jasaItem?->is_reviewed === true;

        // Completion Evidences
        $completionEvidences = $jasaItem?->completionEvidences ?? collect();

        // Service type label
        $serviceTypeMap = [
            'online' => 'Online',
            'di_tempat_umkm' => 'Di Tempat UMKM',
            'at_location' => 'Di Tempat UMKM',
            'ke_rumah_pelanggan' => 'Ke Rumah Pelanggan',
            'on_site' => 'Ke Rumah Pelanggan',
        ];
        $serviceTypeLabel = $serviceTypeMap[$serviceType] ?? ucfirst($serviceType ?? '-');

        // Order method label
        $orderMethodMap = [
            'booking' => 'Booking (Pilih Tanggal & Jam)',
            'keranjang' => 'Tanpa Jadwal',
            'walk_in' => 'Walk-in',
            'konsultasi' => 'Konsultasi',
        ];
        $orderMethodLabel = $orderMethodMap[$jasaItem?->order_method] ?? $jasaItem?->order_method ?? '';

        return [
            'id' => $order->id,
            'jasa_order_item_id' => $jasaItem?->id,
            'order_number' => 'SO-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            // Customer Info - gunakan SNAPSHOT accessor
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $jasaItem?->service_location_address,
            'booking_date' => $jasaItem?->booking_date,
            'booking_time' => $jasaItem?->booking_time,
            'payment_method' => $paymentMethod,
            'payment_method_display' => $paymentMethodDisplay,
            'payment_status' => $paymentStatus,
            'payment_status_display' => $paymentStatusDisplay,
            'payment_channel' => $paymentChannel,
            'paid_channel' => $order->paid_channel,
            'is_payment_completed' => $isPaymentCompleted,
            'payment' => $paymentRelation ? [
                'id' => $paymentRelation->id,
                'status' => $paymentRelation->status,
                'payment_method' => $paymentRelation->payment_method,
                'paid_channel' => $paymentRelation->paid_channel,
                'paid_at' => $paymentRelation->paid_at?->toIso8601String(),
                'xendit_invoice_id' => $paymentRelation->xendit_invoice_id,
            ] : null,
            'total_price' => $totalPrice,
            'service_type' => $serviceType,
            'service_type_label' => $serviceTypeLabel,
            // Service Info - gunakan SNAPSHOT accessor
            'service_name' => $jasaItem?->jasa_title,
            'service_image' => $jasaItem?->jasa_image_url,
            'order_method' => $jasaItem?->order_method,
            'order_method_label' => $orderMethodLabel,
            'order_type' => $order->order_type,
            'mekanisme_pemesanan' => $jasaItem?->order_method,
            'order_status' => $order->status,
            'status' => $status,
            'status_label' => $statusLabel,
            'booking_note' => $jasaItem?->booking_note ?? $jasaItem?->note,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            // Merchant Info - gunakan SNAPSHOT accessor
            'merchant' => [
                'id' => $orderArray['merchant']['id'] ?? null,
                'name' => $order->merchant_name, // snapshot > live
                'slug' => $orderArray['merchant']['slug'] ?? null,
                'address' => $merchantAddress,
            ],
            // Jasa Info - gunakan SNAPSHOT accessor
            'jasa' => [
                'id' => $jasaItem?->jasa_id,
                'title' => $jasaItem?->jasa_title, // snapshot > live
                'image' => $jasaItem?->jasa_image_snapshot, // snapshot raw
                'image_url' => $jasaItem?->jasa_image_url, // snapshot with asset()
            ],
            'review' => $review ? array_merge($review->toArray(), [
                'media' => $review->media->map(function ($media) {
                    $arr = $media->toArray();
                    if (!isset($arr['file_url']) || $arr['file_url'] === '') {
                        $arr['file_url'] = $media->file_url;
                    }
                    return $arr;
                })->toArray()
            ]) : null,
            'is_reviewed' => $isReviewed,
            'completion_note' => $jasaItem?->completion_note,
            'completion_evidences' => $completionEvidences->map(function ($evidence) {
                return [
                    'id' => $evidence->id,
                    'jasa_order_item_id' => $evidence->jasa_order_item_id,
                    'file_path' => $evidence->file_path,
                    'file_url' => $evidence->file_url,
                    'image_url' => $evidence->file_url,
                    'url' => $evidence->file_url,
                    'file_type' => $evidence->file_type ?? ($evidence->is_video ? 'video' : 'image'),
                    'note' => $evidence->note ?? null,
                    'created_at' => $evidence->created_at?->toIso8601String(),
                ];
            })->toArray(),
            'display_address' => $displayAddress,
            'address_label' => match ($serviceType) {
                'online' => 'Lokasi',
                'di_tempat_umkm', 'at_location' => 'Lokasi UMKM',
                default => 'Alamat',
            },
        ];
    }

    /**
     * Customer confirms service completion (NEW ARCHITECTURE)
     *
     * Flow:
     * 1. Find Order by user_id and id
     * 2. Validate status is menunggu_konfirmasi_selesai
     * 3. Validate at least one completion evidence exists
     * 4. Update orders.status = selesai
     * 5. Update jasa_order_items: customer_confirmed = true
     * 6. Return order with fresh evidences
     */
    public function confirmCompleted(Request $request, int $id)
    {
        $customerId = Auth::id();

        // Find order in orders table (PRIMARY)
        $order = Order::with(['jasaItems.completionEvidences'])
            ->where('user_id', $customerId)
            ->whereHas('jasaItems')
            ->find($id);

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $jasaItem = $order->jasaItems->first();

        // Check if order is in correct status
        $currentStatus = $order->status;
        if ($currentStatus !== 'menunggu_konfirmasi_selesai') {
            $statusLabel = $this->getServiceStatusLabel($currentStatus);
            return ApiResponse::error(
                "Pesanan tidak dapat dikonfirmasi. Status saat ini: {$statusLabel}",
                400
            );
        }

        // Check that there is at least one completion evidence
        $evidenceCount = $jasaItem ? $jasaItem->completionEvidences()->count() : 0;
        if ($evidenceCount === 0) {
            return ApiResponse::error('Bukti pengerjaan belum tersedia', 400);
        }

        DB::beginTransaction();
        try {
            // Update orders table (PRIMARY)
            $order->update(['status' => 'selesai']);

            // Update jasa_order_items
            if ($jasaItem) {
                $jasaItem->update([
                    'customer_confirmed' => true,
                    'customer_confirmed_at' => now(),
                ]);
            }

            DB::commit();

            // Return updated data with fresh relations
            return ApiResponse::success(
                $order->fresh(['jasaItems.completionEvidences']),
                'Pesanan berhasil dikonfirmasi selesai. Terima kasih!'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Gagal mengkonfirmasi pesanan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get human-readable status label for orders.status
     * NEW ARCHITECTURE: Uses service status values directly (no more generic: pending/proses/batal)
     * - menunggu_konfirmasi_merchant
     * - diterima
     * - ditolak
     * - layanan_dikerjakan
     * - menunggu_konfirmasi_selesai
     * - selesai
     * - dibatalkan
     */
    private function getServiceStatusLabel(string $status): string
    {
        $labels = [
            'menunggu_konfirmasi_merchant' => 'Menunggu Konfirmasi',
            'pending' => 'Menunggu Konfirmasi',
            'diterima' => 'Diterima',
            'ditolak' => 'Ditolak',
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
            // Legacy generic statuses (for backward compat with old orders)
            'proses' => 'Sedang Diproses',
        ];

        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Get orders status label (DEPRECATED - use getServiceStatusLabel instead)
     * Kept for backward compatibility only
     */
    private function getOrdersStatusLabel(string $status): string
    {
        return $this->getServiceStatusLabel($status);
    }

    /**
     * Get service type label for API response
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
     * Get order method (cara pemesanan) label for API response
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
     * Get service_location_address based on service_type
     *
     * Rules:
     * - di_tempat_umkm  → merchant address (from orders snapshot)
     * - ke_rumah_pelanggan → customer address (from orders snapshot, NOT profile)
     * - online          → "Online"
     */
    private function getServiceLocationAddress(?string $serviceType, Order $order, ?JasaOrderItem $jasaItem): ?string
    {
        $type = $serviceType ?? '';

        if ($type === 'online') {
            return 'Online';
        }

        if (in_array($type, ['di_tempat_umkm', 'ditempat_umkm', 'at_location', 'at_merchant'])) {
            // Merchant address — prioritize snapshot, fallback to live merchant primaryAddress with full geographic detail
            if (!empty($order->merchant_address_snapshot)) {
                return $order->merchant_address_snapshot;
            }
            $addr = $order->merchant?->primaryAddress;
            return $addr?->full_address;
        }

        if (in_array($type, ['ke_rumah_pelanggan', 'ke_tempat_pelanggan', 'on_site', 'customer_location'])) {
            // Customer address from order snapshot — NOT from user profile
            return $order->customer_address_snapshot
                ?? $order->alamat
                ?? $jasaItem?->service_location_address
                ?? null;
        }

        // Fallback for unknown type: return merchant address
        if (!empty($order->merchant_address_snapshot)) {
            return $order->merchant_address_snapshot;
        }
        $addr = $order->merchant?->primaryAddress;
        return $addr?->full_address;
    }

    /**
     * Auto-expire order if merchant_response_deadline has passed.
     * Returns true if order was expired, false otherwise.
     * Source of truth: orders.merchant_response_deadline and orders.status.
     */
    private function autoExpireOrder(Order $order): bool
    {
        $pendingStatuses = [
            'menunggu_konfirmasi',
            'menunggu_konfirmasi_merchant',
            'pending',
        ];

        if (!in_array($order->status, $pendingStatuses)) {
            return false;
        }

        if (!$order->merchant_response_deadline) {
            return false;
        }

        if ($order->merchant_response_deadline->isFuture()) {
            return false;
        }

        // Deadline has passed — expire the order
        $order->update([
            'status' => 'expired',
            'expired_at' => now(),
        ]);

        // Update in-memory model so transformations pick up the new status without extra query
        $order->status = 'expired';
        $order->expired_at = now();

        Log::info('[autoExpireOrder] Order expired', [
            'order_id' => $order->id,
            'deadline' => $order->merchant_response_deadline->toISOString(),
        ]);

        return true;
    }

    /**
     * Get customer address for API response (snapshot, NOT profile)
     */
    private function getCustomerAddress(Order $order, ?JasaOrderItem $jasaItem): ?string
    {
        return $jasaItem?->service_location_address
            ?? $order->customer_address_snapshot
            ?? $order->alamat
            ?? null;
    }

    /**
     * Get merchant full address from primaryAddress + province/city/district/village.
     * Uses snapshot address_snapshot as primary, falls back to live merchant primaryAddress.
     */
    private function getMerchantFullAddress(Order $order): ?string
    {
        // Prioritize snapshot
        if (!empty($order->merchant_address_snapshot)) {
            return $order->merchant_address_snapshot;
        }

        // Fall back to live merchant primaryAddress (with geographic relations loaded)
        $addr = $order->merchant?->primaryAddress;
        if ($addr) {
            return $addr->full_address;
        }

        return null;
    }

    /**
     * Mapping from frontend status to orders.status for filtering
     * Frontend might send: pending, diterima, ditolak, dikerjakan, selesai, dibatalkan
     * Orders ENUM: pending, proses, selesai, batal
     */
    private function mapFrontendStatusToOrdersStatus(string $frontendStatus): ?string
    {
        $mapping = [
            'pending' => 'pending',
            'pending_confirmation' => 'pending',
            'diterima' => 'proses',
            'accepted' => 'proses',
            'ditolak' => 'batal',
            'rejected' => 'batal',
            'dibatalkan' => 'batal',
            'cancelled' => 'batal',
            'dikerjakan' => 'proses',
            'in_progress' => 'proses',
            'layanan_dikerjakan' => 'proses',
            'selesai' => 'selesai',
            'completed' => 'selesai',
        ];

        return $mapping[$frontendStatus] ?? null;
    }

    /**
     * Map frontend mechanism to internal order_method format.
     *
     * Frontend sends: keranjang/booking/konsultasi/direct_checkout/etc.
     * We store: keranjang, booking, konsultasi (frontend display format)
     */
    private function mapMekanismeToOrderMethod(?string $mekanisme): ?string
    {
        if (!$mekanisme) {
            return null;
        }

        $mapping = [
            // Frontend values -> internal values
            'konsultasi' => 'konsultasi',
            'booking' => 'booking',
            'keranjang' => 'keranjang',
            // Aliases
            'langsung_pesan' => 'keranjang',
            'langsung_pesan_lagi' => 'keranjang',
            'checkout' => 'keranjang',
            'direct_checkout' => 'keranjang',
            'consultation' => 'konsultasi',
            'scheduled' => 'booking',
            'direct' => 'keranjang',
            'memerlukan_konsultasi' => 'konsultasi',
        ];

        return $mapping[strtolower($mekanisme)] ?? null;
    }

    /**
     * Get order_type from various sources (orders, jasa_order_items, service_orders)
     * Used for unified access across all tables
     *
     * Priority:
     * 1. jasa_order_items.order_method (PRIMARY - mechanism)
     * 2. orders.order_type (legacy - but for jasa should be 'jasa')
     */
    private function resolveOrderType(?Order $order, ?JasaOrderItem $jasaItem): ?string
    {
        // 1. Check jasa_order_items.order_method (PRIMARY)
        if ($jasaItem && !empty($jasaItem->order_method)) {
            return $jasaItem->order_method;
        }

        // 2. Check orders.order_type - for jasa orders this should be 'jasa'
        // But if it's consultation/booking/direct, treat as order_method
        if ($order && !empty($order->order_type)) {
            $orderType = $order->order_type;
            // If it's not 'jasa' or 'product', it's a legacy order_method
            if (!in_array($orderType, ['jasa', 'product'])) {
                return $orderType;
            }
        }

        // 3. Fallback to 'keranjang' (FE format)
        return 'keranjang';
    }

    /**
     * Get merchant orders from orders table (JASA)
     *
     * This endpoint uses orders as the primary source with jasa_order_items for service details.
     * Compatible with frontend Index.vue - Pesanan Masuk tab Jasa.
     *
     * @param Request $request
     * @param Merchant $merchant - supports both id and slug via {merchant:slug} route binding
     */
    public function getMerchantOrders(Request $request, Merchant $merchant)
    {
        // Authorization: current user must own this merchant
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses ke merchant ini', 403);
        }

        Log::info('[getMerchantOrders] Request received', [
            'merchant_id' => $merchant->id,
            'merchant_slug' => $merchant->slug,
            'params' => $request->all(),
        ]);

        // Audit Logs (Log target 10)
        $totalOrdersJasaBeforeFilter = Order::where('order_type', 'jasa')->count();
        $totalOrdersJasaAfterMerchant = Order::where('order_type', 'jasa')->where('merchant_id', $merchant->id)->count();
        
        Log::info('[getMerchantOrders] Audit count before status filter', [
            'total_jasa_before_filter' => $totalOrdersJasaBeforeFilter,
            'total_jasa_after_merchant_id_filter' => $totalOrdersJasaAfterMerchant,
        ]);

        // Build query from orders table
        // NOTE: jasa_id ada di jasa_order_items, BUKAN di orders
        // NOTE: Load jasaItems jasa with only needed columns for performance.
        // Use snapshot accessors as primary source, fallback to live jasa.service_type.
        $query = Order::with([
            'user:id,name,phone',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province',
            'merchant.primaryAddress.city',
            'merchant.primaryAddress.district',
            'merchant.primaryAddress.village',
            'jasaItems:id,order_id,jasa_id,jasa_title_snapshot,jasa_image_snapshot,booking_date,booking_time,booking_note,service_type,order_method,service_location_address,completion_note,is_reviewed,review_id,customer_latitude,customer_longitude',
            'jasaItems.jasa:id,service_type',
            'jasaItems.jasa.categories',
            'jasaItems.review.media',
            'jasaItems.review.histories',
            'jasaItems.completionEvidences',
        ])
            ->where('merchant_id', $merchant->id)
            ->where('order_type', 'jasa')
            ->whereHas('jasaItems')
            ->orderByDesc('created_at');

        // Filter by status if provided
        if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
            $status = $request->get('status');
            $query->where('status', $status);

            $totalOrdersJasaAfterStatus = Order::where('order_type', 'jasa')
                ->where('merchant_id', $merchant->id)
                ->where('status', $status)
                ->count();
            Log::info('[getMerchantOrders] Audit count after status filter', [
                'status_value' => $status,
                'total_jasa_after_status_filter' => $totalOrdersJasaAfterStatus,
            ]);
        }

        // Search by order code or customer name
        if ($request->has('q') && !empty($request->q)) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('order_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by date range
        if ($request->has('start_date') && !empty($request->start_date)) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date') && !empty($request->end_date)) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'newest');
        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);
        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform orders to API response format
        $transformedData = collect($orders->items())->map(function ($order) {
            // Auto-expire if deadline passed (source of truth: orders table)
            $this->autoExpireOrder($order);

            $jasaItem = $order->jasaItems->first();

            // Completion Evidences - dari jasa_order_items (PRIMARY)
            $completionEvidences = $jasaItem?->completionEvidences ?? collect();

            // Debug logging
            Log::info('[getMerchantOrders] Evidence debug', [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'evidence_count' => $completionEvidences->count(),
            ]);

            return [
                // IDs
                'id' => $order->id,
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'order_number' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'formatted_order_number' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),

                // Status (from orders table as primary)
                'order_status' => $order->status,
                'order_status_label' => $this->getServiceStatusLabel($order->status),
                'status' => $order->status, // PRIMARY: direct from orders.status
                'status_label' => $this->getServiceStatusLabel($order->status), // PRIMARY: direct label

                // Customer Info - gunakan SNAPSHOT accessor
                'customer_name' => $order->customer_name, // snapshot > user > nama
                'customer_phone' => $order->customer_phone, // snapshot > user > tel
                'customer_address' => $jasaItem?->service_location_address,
                'customer' => [
                    'id' => $order->user_id,
                    'name' => $order->customer_name, // snapshot > user > nama
                    'phone' => $order->customer_phone, // snapshot > user > tel
                ],

                // Payment Info
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'payment_channel' => $order->payment_channel ?? $order->paid_channel,

                // Service/Jasa Info - gunakan SNAPSHOT accessor, fallback ke live jasas.service_type
                'service_name' => $jasaItem?->jasa_title, // snapshot > live
                'service_image' => $jasaItem?->jasa_image_url, // snapshot > live
                'service_type' => $jasaItem?->service_type ?? $jasaItem?->jasa?->service_type,
                'service_type_label' => $this->getServiceTypeLabel($jasaItem?->service_type ?? $jasaItem?->jasa?->service_type),
                'category_name' => $jasaItem?->jasa?->categories?->first()?->name,
                'order_type' => $order->order_type, // Legacy: mechanism (jasa/product)
                'booking_type' => $jasaItem?->order_method,

                // Booking Info
                'booking_date' => $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time,
                'booking_note' => $jasaItem?->booking_note ?? $jasaItem?->note,

                // Order method / cara pemesanan
                'cara_pemesanan' => $jasaItem?->order_method,
                'cara_pemesanan_label' => $this->getOrderMethodLabel($jasaItem?->order_method),

                // Addresses — use helper based on service_type
                'service_location_address' => $this->getServiceLocationAddress(
                    $jasaItem?->service_type ?? $jasaItem?->jasa?->service_type,
                    $order,
                    $jasaItem
                ),
                'customer_address' => $this->getCustomerAddress($order, $jasaItem),
                'merchant_address' => $this->getMerchantFullAddress($order),

                // Pricing
                'total_price' => (float) $order->total_price,

                // Completion
                'completion_note' => $jasaItem?->completion_note,
                'rejection_reason' => $order->rejection_reason,

                // Review (from jasa_order_items)
                'review' => $jasaItem?->review ? array_merge($jasaItem->review->toArray(), [
                    'media' => $jasaItem->review->media->map(function ($media) {
                        $arr = $media->toArray();
                        $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                        return $arr;
                    })->toArray()
                ]) : null,
                'is_reviewed' => $jasaItem?->is_reviewed === true,

                // Completion Evidences (from jasa_order_items only - NEW ARCHITECTURE)
                'completion_evidences' => $completionEvidences->map(function ($evidence) {
                    return [
                        'id' => $evidence->id,
                        'jasa_order_item_id' => $evidence->jasa_order_item_id,
                        'file_path' => $evidence->file_path,
                        'file_url' => $evidence->file_url,
                        'image_url' => $evidence->file_url,
                        'url' => $evidence->file_url,
                        'file_type' => $evidence->file_type ?? ($evidence->is_video ? 'video' : 'image'),
                        'note' => $evidence->note ?? null,
                        'created_at' => $evidence->created_at?->toIso8601String(),
                    ];
                })->toArray(),

                // Timestamps
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
                // SLA timestamps
                'merchant_response_deadline' => $order->merchant_response_deadline?->toIso8601String(),
                'merchant_responded_at' => $order->merchant_responded_at?->toIso8601String(),
                'completion_submitted_at' => $order->completion_submitted_at?->toIso8601String(),
                'completion_deadline_at' => $order->completion_deadline_at?->toIso8601String(),
                'completed_at' => $order->completed_at?->toIso8601String(),
                'completed_by' => $order->completed_by,
                'auto_completed_at' => $order->auto_completed_at?->toIso8601String(),
                'cancelled_by' => $order->cancelled_by,
                'rejected_by' => $order->rejected_by,
                'expired_at' => $order->expired_at?->toIso8601String(),
                // Completion note
                'completion_note' => $jasaItem?->completion_note ?? null,

                // Merchant Info - gunakan SNAPSHOT accessor, fallback ke live primaryAddress + province/city/district/village
                'merchant' => [
                    'id' => $order->merchant_id,
                    'name' => $order->merchant_name, // snapshot > live
                    'address' => $this->getMerchantFullAddress($order), // snapshot > live primaryAddress
                ],

                // Jasa Info - gunakan SNAPSHOT accessor
                'jasa' => [
                    'id' => $jasaItem?->jasa_id,
                    'title' => $jasaItem?->jasa_title, // snapshot > live
                    'image' => $jasaItem?->jasa_image_snapshot, // snapshot raw
                    'image_url' => $jasaItem?->jasa_image_url, // snapshot with asset()
                ],
            ];
        })->toArray();

        // Build paginated response
        $response = [
            'current_page' => $orders->currentPage(),
            'data' => $transformedData,
            'first_page_url' => $orders->url(1),
            'from' => $orders->firstItem(),
            'last_page' => $orders->lastPage(),
            'last_page_url' => $orders->url($orders->lastPage()),
            'next_page_url' => $orders->nextPageUrl(),
            'path' => $orders->path(),
            'per_page' => $orders->perPage(),
            'prev_page_url' => $orders->previousPageUrl(),
            'to' => $orders->lastItem(),
            'total' => $orders->total(),
        ];

        Log::info('[getMerchantOrders] Response built', [
            'merchant_id' => $merchant->id,
            'total_orders' => $orders->total(),
            'current_page' => $orders->currentPage(),
            'final_json_response' => $response,
        ]);

        return ApiResponse::success($response, 'success');
    }

    /**
     * Update merchant order status (from orders table)
     *
     * @param Request $request
     * @param Merchant $merchant
     * @param int $id - order id from orders table
     */
    public function updateMerchantOrderStatus(Request $request, Merchant $merchant, int $id)
    {
        // Reuse the existing updateStatus method
        return $this->updateStatus($request, $merchant, $id);
    }

    /**
     * Get single service order detail (for merchant)
     *
     * Refactored: Uses orders as primary source, jasa_order_items for service details,
     * service_orders for backward compatibility (status, review, evidences)
     */
    public function getMerchantOrderDetail(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        // Find order in orders table
        // Use snapshot accessors as primary source, fallback to live jasa.service_type.
        $order = Order::with([
            'user:id,name,phone',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province',
            'merchant.primaryAddress.city',
            'merchant.primaryAddress.district',
            'merchant.primaryAddress.village',
            'jasaItems:id,order_id,jasa_id,jasa_title_snapshot,jasa_image_snapshot,booking_date,booking_time,booking_note,service_type,order_method,service_location_address,completion_note,customer_latitude,customer_longitude,is_reviewed,review_id',
            'jasaItems.jasa:id,service_type',
            'jasaItems.jasa.categories',
            'jasaItems.review.media',
            'jasaItems.review.histories',
            'jasaItems.completionEvidences',
        ])
            ->where('merchant_id', $merchant->id)
            ->whereHas('jasaItems')
            ->find($id);

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Auto-expire if deadline passed (source of truth: orders table)
        $this->autoExpireOrder($order);

        $jasaItem = $order->jasaItems->first();
        $transformedOrder = $this->transformMerchantOrderOnly($order, $jasaItem);

        return ApiResponse::success($transformedOrder, 'success');
    }

    /**
     * Transform order for merchant-facing responses (NEW ARCHITECTURE)
     */
    private function transformMerchantOrderOnly(Order $order, ?JasaOrderItem $jasaItem): array
    {
        $orderArray = $order->toArray();

        // STATUS
        $status = $order->status;
        $statusLabel = $this->getServiceStatusLabel($order->status);

        // PAYMENT
        $paymentStatus = $order->payment_status;
        $paymentMethod = $order->payment_method;
        $totalPrice = (float) $order->total_price;

        // COMPLETION EVIDENCES
        $completionEvidences = $jasaItem?->completionEvidences ?? collect();

        // REVIEW
        $review = $jasaItem?->review;
        $isReviewed = $jasaItem?->is_reviewed === true;

        return [
            'id' => $order->id,
            'jasa_order_item_id' => $jasaItem?->id,
            'order_number' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            // Customer Info - gunakan SNAPSHOT accessor
            'customer_name' => $order->customer_name, // snapshot > user > nama
            'customer_phone' => $order->customer_phone, // snapshot > user > tel
            'customer_address' => $this->getCustomerAddress($order, $jasaItem),
            'customer_latitude' => $jasaItem?->customer_latitude ?? null,
            'customer_longitude' => $jasaItem?->customer_longitude ?? null,
            'booking_date' => $jasaItem?->booking_date,
            'booking_time' => $jasaItem?->booking_time,
            'booking_note' => $jasaItem?->booking_note ?? $jasaItem?->note,
            'payment_method' => $paymentMethod,
            'payment_channel' => $order->payment_channel ?? $order->paid_channel,
            'payment_status' => $paymentStatus,
            'total_price' => $totalPrice,
            'service_type' => $jasaItem?->service_type ?? $jasaItem?->jasa?->service_type,
            'service_type_label' => $this->getServiceTypeLabel($jasaItem?->service_type ?? $jasaItem?->jasa?->service_type),
            'category_name' => $jasaItem?->jasa?->categories?->first()?->name,
            // Service Info - gunakan SNAPSHOT accessor
            'service_name' => $jasaItem?->jasa_title, // snapshot > live
            'service_image' => $jasaItem?->jasa_image_url, // snapshot > live
            'order_type' => $order->order_type,
            'mekanisme_pemesanan' => $jasaItem?->order_method,
            'cara_pemesanan' => $jasaItem?->order_method,
            'cara_pemesanan_label' => $this->getOrderMethodLabel($jasaItem?->order_method),
            'status' => $status,
            'status_label' => $statusLabel,
            'completion_note' => $jasaItem?->completion_note,
            'rejection_reason' => $order->rejection_reason,
            // Address snapshots
            'merchant_address' => $this->getMerchantFullAddress($order),
            'service_location_address' => $this->getServiceLocationAddress(
                $jasaItem?->service_type ?? $jasaItem?->jasa?->service_type,
                $order,
                $jasaItem
            ),
            // SLA timestamps
            'merchant_response_deadline' => $order->merchant_response_deadline?->toIso8601String(),
            'merchant_responded_at' => $order->merchant_responded_at?->toIso8601String(),
            'completion_submitted_at' => $order->completion_submitted_at?->toIso8601String(),
            'completion_deadline_at' => $order->completion_deadline_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'completed_by' => $order->completed_by,
            'auto_completed_at' => $order->auto_completed_at?->toIso8601String(),
            'cancelled_by' => $order->cancelled_by,
            'rejected_by' => $order->rejected_by,
            'expired_at' => $order->expired_at?->toIso8601String(),
            // Completion note from merchant's evidence upload
            'completion_note' => $jasaItem?->completion_note ?? null,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            // Customer detail - gunakan SNAPSHOT accessor
            'customer' => [
                'id' => $orderArray['user']['id'] ?? null,
                'name' => $order->customer_name, // snapshot > user > nama
                'phone' => $order->customer_phone, // snapshot > user > tel
            ],
            // Merchant Info - gunakan SNAPSHOT accessor, fallback ke live primaryAddress + province/city/district/village
            'merchant' => [
                'id' => $order->merchant_id,
                'name' => $order->merchant_name, // snapshot > live
                'phone' => $order->merchant_phone, // snapshot > live
                'address' => $this->getMerchantFullAddress($order), // snapshot > live primaryAddress
            ],
            // Jasa Info - gunakan SNAPSHOT accessor
            'jasa' => [
                'id' => $jasaItem?->jasa_id,
                'title' => $jasaItem?->jasa_title, // snapshot > live
                'image' => $jasaItem?->jasa_image_snapshot, // snapshot raw
                'image_url' => $jasaItem?->jasa_image_url, // snapshot with asset()
            ],
            'review' => $review ? array_merge($review->toArray(), [
                'media' => $review->media->map(function ($media) {
                    $arr = $media->toArray();
                    $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                    return $arr;
                })->toArray()
            ]) : null,
            'is_reviewed' => $isReviewed,
            'completion_evidences' => $completionEvidences->map(function ($evidence) {
                return [
                    'id' => $evidence->id,
                    'jasa_order_item_id' => $evidence->jasa_order_item_id,
                    'file_path' => $evidence->file_path,
                    'file_url' => $evidence->file_url,
                    'image_url' => $evidence->file_url,
                    'url' => $evidence->file_url,
                    'file_type' => $evidence->file_type ?? ($evidence->is_video ? 'video' : 'image'),
                    'note' => $evidence->note ?? null,
                    'created_at' => $evidence->created_at?->toIso8601String(),
                ];
            })->toArray(),
        ];
    }

    /**
     * Update service order status (merchant actions)
     *
     * PRIMARY: orders.id + jasa_order_items
     * NO service_orders dependency for new orders.
     *
     * Flow:
     * 1. Receive {id} from route as orders.id
     * 2. Find Order with relations (jasa_order_items)
     * 3. Update orders.status and jasa_order_items fields
     */
    public function updateStatus(Request $request, Merchant $merchant, $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        if ($merchant->segmentation_id !== 3) {
            return ApiResponse::error('Merchant ini tidak memiliki layanan jasa', 400);
        }

        // Normalize status aliases from frontend
        $requestedStatus = $request->input('status');
        $statusAliases = [
            'accepted' => 'diterima',
            'diterima' => 'diterima',
            'rejected' => 'ditolak',
            'ditolak' => 'ditolak',
            'in_progress' => 'layanan_dikerjakan',
            'dikerjakan' => 'layanan_dikerjakan',
            'completed' => 'selesai',
            'selesai' => 'selesai',
            'menunggu_konfirmasi_selesai' => 'menunggu_konfirmasi_selesai',
        ];
        $normalizedStatus = $statusAliases[$requestedStatus] ?? $requestedStatus;

        if ($normalizedStatus !== $requestedStatus) {
            $request->merge(['status' => $normalizedStatus]);
        }

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    'diterima',
                    'ditolak',
                    'layanan_dikerjakan',
                    'menunggu_konfirmasi_selesai',
                    'selesai',
                ]),
            ],
            'rejection_reason' => 'required_if:status,ditolak|nullable|string|max:500',
            'completion_note' => 'nullable|string|max:1000',
            'evidences' => 'nullable|array|max:5',
            'evidences.*' => 'file|max:51200|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm',
        ]);

        $newStatus = $data['status'];

        // ============================================================
        // FIND DATA - orders.id + jasa_order_items (NO service_orders)
        // ============================================================
        $order = Order::with(['jasaItems'])
            ->where('merchant_id', $merchant->id)
            ->whereHas('jasaItems')
            ->find($id);

        if (!$order) {
            Log::warning('[ServiceOrder UpdateStatus] Order not found', [
                'received_id' => $id,
                'merchant_id' => $merchant->id,
            ]);
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        Log::info('[ServiceOrder UpdateStatus] Found order', [
            'received_id' => $id,
            'orders_id' => $order->id,
            'order_status' => $order->status,
        ]);

        $jasaOrderItem = $order->jasaItems->first();

        // Check terminal status
        $terminalStatuses = ['selesai', 'ditolak'];
        if (in_array($order->status, $terminalStatuses)) {
            $statusLabel = $this->getServiceStatusLabel($order->status);
            Log::warning('[ServiceOrder UpdateStatus] Order in terminal state', [
                'received_id' => $id,
                'current_status' => $order->status,
                'requested_status' => $newStatus,
            ]);
            return ApiResponse::error(
                "Pesanan sudah dalam status akhir ({$statusLabel}) dan tidak dapat diubah lagi.",
                400
            );
        }

        try {
            DB::beginTransaction();

            // ============================================================
            // UPDATE STATUS - orders + jasa_order_items ONLY
            // ============================================================
            $orderTimestampData = [];

            // Handle DITOLAK (rejection)
            if ($newStatus === 'ditolak') {
                $rejectionReason = $data['rejection_reason'] ?? 'Merchant menolak pesanan';
                $orderTimestampData['status'] = $newStatus;
                $orderTimestampData['rejected_at'] = now();
                $orderTimestampData['rejection_reason'] = $rejectionReason;
                $order->update($orderTimestampData);

                Log::info('[ServiceOrder UpdateStatus] Order rejected', [
                    'received_id' => $id,
                    'orders_id' => $order->id,
                    'new_status' => $newStatus,
                    'rejection_reason' => $rejectionReason,
                ]);
            }
            // Handle DITERIMA (accepted)
            elseif ($newStatus === 'diterima') {
                $orderTimestampData['status'] = $newStatus;
                $orderTimestampData['accepted_at'] = now();
                $orderTimestampData['responsed_at'] = now();
                $order->update($orderTimestampData);

                Log::info('[ServiceOrder UpdateStatus] Order accepted', [
                    'received_id' => $id,
                    'orders_id' => $order->id,
                    'new_status' => $newStatus,
                ]);
            }
            // Handle DIKERJAKAN (started working)
            elseif ($newStatus === 'layanan_dikerjakan') {
                $orderTimestampData['status'] = $newStatus;
                $orderTimestampData['started_at'] = now();
                $order->update($orderTimestampData);

                Log::info('[ServiceOrder UpdateStatus] Order started working', [
                    'received_id' => $id,
                    'orders_id' => $order->id,
                    'new_status' => $newStatus,
                ]);
            }

            // Handle SELESAI or MENUNGGU_KONFIRMASI_SELESAI (completion or waiting customer confirmation)
            elseif ($newStatus === 'selesai' || $newStatus === 'menunggu_konfirmasi_selesai') {
                $files = $request->file('evidences', []);
                $uploadedEvidences = [];

                foreach ($files as $index => $file) {
                    $error = ServiceCompletionEvidence::validateFile($file);
                    if ($error) {
                        throw new \Exception("File {$file->getClientOriginalName()}: {$error}");
                    }

                    $type = ServiceCompletionEvidence::getFileType($file->getMimeType());
                    $path = ServiceCompletionEvidence::generatePath(
                        $file->getClientOriginalName(),
                        $type === 'image' ? 'images' : 'videos'
                    );

                    Storage::disk('public')->put($path, file_get_contents($file));

                    // Store evidence with jasa_order_item_id and order_id
                    // file_url generated by accessor from file_path
                    $evidence = ServiceCompletionEvidence::create([
                        'jasa_order_item_id' => $jasaOrderItem?->id,
                        'order_id' => $order->id,
                        'service_order_id' => null,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_type' => $type,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'display_order' => $index,
                        'note' => $data['completion_note'] ?? null,
                    ]);
                    $uploadedEvidences[] = $evidence;

                    Log::info('[ServiceOrder UpdateStatus] Evidence uploaded', [
                        'evidence_id' => $evidence->id,
                        'jasa_order_item_id' => $jasaOrderItem?->id,
                        'file_name' => $file->getClientOriginalName(),
                    ]);
                }

                // Update completion_note on jasa_order_items
                if (!empty($data['completion_note']) && $jasaOrderItem) {
                    $jasaOrderItem->update(['completion_note' => $data['completion_note']]);
                }

                // Update orders table
                $orderTimestampData = ['status' => $newStatus];
                if ($newStatus === 'selesai') {
                    $orderTimestampData['completed_at'] = now();
                    $orderTimestampData['delivered_at'] = now();
                } else {
                    $orderTimestampData['completion_submitted_at'] = now();
                    $orderTimestampData['completion_deadline_at'] = now()->addDays(3);
                }
                $order->update($orderTimestampData);

                Log::info('[ServiceOrder UpdateStatus] Order completion state updated', [
                    'received_id' => $id,
                    'orders_id' => $order->id,
                    'jasa_order_item_id' => $jasaOrderItem?->id,
                    'new_status' => $newStatus,
                    'files_uploaded' => count($uploadedEvidences),
                ]);
            }
            // No other cases
            else {
                $order->update(['status' => $newStatus]);

                Log::info('[ServiceOrder UpdateStatus] Status updated', [
                    'received_id' => $id,
                    'orders_id' => $order->id,
                    'new_status' => $newStatus,
                ]);
            }

            DB::commit();

            // Reload data for response
            $freshOrder = $order->fresh(['jasaItems.completionEvidences', 'user']);
            $freshJasaItem = $freshOrder?->jasaItems->first();
            $freshEvidences = $freshJasaItem?->completionEvidences ?? collect();

            $transformedEvidences = $freshEvidences->map(function ($evidence) {
                return [
                    'id' => $evidence->id,
                    'jasa_order_item_id' => $evidence->jasa_order_item_id,
                    'file_name' => $evidence->file_name,
                    'file_path' => $evidence->file_path,
                    'file_url' => $evidence->file_url,
                    'image_url' => $evidence->file_url,
                    'url' => $evidence->file_url,
                    'file_type' => $evidence->file_type,
                    'mime_type' => $evidence->mime_type,
                    'file_size' => $evidence->file_size,
                    'display_order' => $evidence->display_order,
                    'note' => $evidence->note ?? null,
                    'created_at' => $evidence->created_at?->toIso8601String(),
                ];
            })->toArray();

            // Build response - NO service_order_id
            $responseData = [
                'id' => $freshOrder->id,
                'order_id' => $freshOrder->id,
                'jasa_order_item_id' => $freshJasaItem?->id,
                'order_status' => $freshOrder->status,
                'order_status_label' => $this->getServiceStatusLabel($freshOrder->status),
                'status' => $freshOrder->status,
                'status_label' => $this->getServiceStatusLabel($freshOrder->status),
                'completion_note' => $freshJasaItem?->completion_note,
                'completion_evidences' => $transformedEvidences,
            ];

            Log::info('[ServiceOrder UpdateStatus] Updated order', [
                'order_id' => $freshOrder->id,
                'orders_status' => $freshOrder->status,
                'evidence_count' => count($transformedEvidences),
            ]);

            return ApiResponse::success($responseData, 'Status berhasil diperbarui');
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            Log::error('[ServiceOrder UpdateStatus] Validation error', [
                'received_id' => $id,
                'orders_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[ServiceOrder UpdateStatus] Exception', [
                'received_id' => $id,
                'orders_id' => $order->id,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ApiResponse::error('Gagal memperbarui status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit service order review (customer action)
     *
     * NEW ARCHITECTURE: Uses orders as primary source
     *
     * Flow:
     * 1. Find Order by user_id and id
     * 2. Validate status is selesai
     * 3. Check no existing review for this order
     * 4. Create Rating with jasa_order_item_id
     * 5. Update jasa_order_items: is_reviewed = true, review_id
     * 6. Update rating summaries
     */
    public function submitReview(Request $request, int $id)
    {
        // Validate required fields
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:80',
            'comment' => 'required|string|min:10|max:500',
            'is_anonymous' => 'nullable',
            'media' => 'nullable',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:10240',
        ]);

        // Find order in orders table (PRIMARY)
        // NOTE: jasa relation not needed (uses jasa_id from item)
        $order = Order::with(['merchant', 'jasaItems'])
            ->where('user_id', Auth::id())
            ->whereHas('jasaItems')
            ->find($id);

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $jasaItem = $order->jasaItems->first();
        $jasaId = $jasaItem?->jasa_id;
        $merchantId = $order->merchant_id;

        // VALIDATION: Order must be completed
        $currentStatus = $order->status;
        if ($currentStatus !== 'selesai') {
            $statusLabel = $this->getServiceStatusLabel($currentStatus);
            return ApiResponse::error(
                "Pesanan harus selesai terlebih dahulu sebelum memberikan review. Status saat ini: {$statusLabel}",
                400
            );
        }

        // VALIDATION: Not already reviewed
        if ($jasaItem) {
            $existingReview = Rating::where('jasa_order_item_id', $jasaItem->id)
                ->where('user_id', Auth::id())
                ->first();

            if ($existingReview) {
                return ApiResponse::error('Anda sudah memberikan review untuk pesanan ini', 409);
            }
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

            // Create rating for the service (jasa)
            $ratingData = [
                'user_id' => Auth::id(),
                'merchant_id' => $merchantId,
                'rateable_id' => $jasaId,
                'rateable_type' => Jasa::class,
                'rating' => (int) $data['rating'],
                'title' => $data['title'] ?? null,
                'comment' => $data['comment'] ?? null,
                'is_anonymous' => $isAnonymous,
            ];

            // Add jasa_order_item_id
            if ($jasaItem) {
                $ratingData['jasa_order_item_id'] = $jasaItem->id;
            }

            Log::info('[submitReview] Creating rating with data:', $ratingData);

            $review = Rating::create($ratingData);

            Log::info('[submitReview] Rating created:', ['id' => $review->id]);

            // Handle media uploads
            if ($request->hasFile('media')) {
                $mediaFiles = $request->file('media');

                if (!is_array($mediaFiles) && $mediaFiles instanceof \Illuminate\Http\UploadedFile) {
                    $mediaFiles = [$mediaFiles];
                }

                Log::info('[submitReview] Processing media files:', [
                    'count' => count($mediaFiles),
                    'type' => gettype($mediaFiles),
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

                        Log::info('[submitReview] Media saved:', [
                            'index' => $index,
                            'path' => "{$type}/{$dateFolder}/{$newFileName}",
                        ]);
                    } catch (\Exception $mediaException) {
                        Log::error('[submitReview] Media save failed:', [
                            'index' => $index,
                            'error' => $mediaException->getMessage(),
                        ]);
                    }
                }
            } else {
                Log::info('[submitReview] No media files uploaded');
            }

            // Update jasa_order_items: mark as reviewed
            if ($jasaItem) {
                $jasaItem->update([
                    'is_reviewed' => true,
                    'review_id' => $review->id,
                ]);
            }

            // Update rating summaries
            $this->updateRatingSummary($jasaId, $merchantId);

            DB::commit();

            // Load relationships for response (include histories for frontend convenience)
            $review->load(['user', 'media', 'histories']);

            Log::info('[submitReview] Success:', ['review_id' => $review->id]);

            return ApiResponse::success([
                'review' => $review,
                'order' => $order,
            ], 'Review berhasil dikirim. Terima kasih atas ulasan Anda!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[submitReview] Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponse::error('Gagal mengirim review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update service order review (customer action)
     *
     * Saves history of review changes.
     * Customer can only update review once.
     *
     * Flow:
     * 1. Find existing review by order and user
     * 2. Check if update is allowed (update_count < 1)
     * 3. Save old data to review_histories
     * 4. Update review with new data
     * 5. Return updated review with history
     */
    public function updateReview(Request $request, int $id)
    {
        // Validate required fields
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:80',
            'comment' => 'required|string|min:10|max:500',
            'is_anonymous' => 'nullable',
            'media' => 'nullable',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:10240',
        ]);

        // Find order in orders table (PRIMARY)
        // NOTE: jasa relation not needed (uses jasa_id from item)
        $order = Order::with(['merchant', 'jasaItems'])
            ->where('user_id', Auth::id())
            ->whereHas('jasaItems')
            ->find($id);

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $jasaItem = $order->jasaItems->first();

        // Find existing review
        $review = Rating::where('jasa_order_item_id', $jasaItem->id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$review) {
            return ApiResponse::error('Review tidak ditemukan', 404);
        }

        // Check if update is allowed (customer can only update once)
        if ($review->update_count >= 1) {
            return ApiResponse::error('Anda sudah pernah memperbarui ulasan ini', 400);
        }

        try {
            DB::beginTransaction();

            // Save old media for history
            $oldMedia = $review->media ? $review->media->toArray() : [];

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

            // Create history record BEFORE updating
            $historyData = [
                'rating_id' => $review->id,
                'old_rating' => $review->rating,
                'old_title' => $review->title,
                'old_comment' => $review->comment,
                'old_media' => $oldMedia,
                'new_rating' => (int) $data['rating'],
                'new_title' => $data['title'] ?? null,
                'new_comment' => $data['comment'],
                'new_media' => [], // Will update after media upload
                'updated_by' => Auth::id(),
            ];

            $history = ReviewHistory::create($historyData);

            // Update review
            $review->rating = (int) $data['rating'];
            $review->title = $data['title'] ?? null;
            $review->comment = $data['comment'];
            $review->is_anonymous = $isAnonymous;
            $review->increment('update_count');
            $review->review_updated_at = now();
            $review->save();

            // Handle new media uploads
            $newMediaArray = [];
            if ($request->hasFile('media')) {
                $mediaFiles = $request->file('media');

                if (!is_array($mediaFiles) && $mediaFiles instanceof \Illuminate\Http\UploadedFile) {
                    $mediaFiles = [$mediaFiles];
                }

                Log::info('[updateReview] Processing media files:', [
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

                        $media = ReviewMedia::create([
                            'review_id' => $review->id,
                            'file_path' => $storedPath,
                            'file_url' => Storage::url($storedPath),
                            'file_type' => $isImage ? 'image' : 'video',
                            'mime_type' => $mimeType,
                            'original_name' => $file->getClientOriginalName(),
                            'file_size' => $file->getSize(),
                            'display_order' => $index,
                        ]);

                        $newMediaArray[] = $media->toArray();

                        Log::info('[updateReview] Media saved:', ['index' => $index, 'path' => $storedPath]);
                    } catch (\Exception $mediaException) {
                        Log::error('[updateReview] Media save failed:', [
                            'index' => $index,
                            'error' => $mediaException->getMessage(),
                        ]);
                    }
                }
            }

            // Update history with new media
            if (!empty($newMediaArray)) {
                $history->update(['new_media' => $newMediaArray]);
            }

            DB::commit();

            // Load relationships for response
            $review->load(['user', 'media', 'histories']);

            Log::info('[updateReview] Success:', ['review_id' => $review->id]);

            return ApiResponse::success([
                'review' => $review,
                'order' => $order,
            ], 'Ulasan berhasil diperbarui!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[updateReview] Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ApiResponse::error('Gagal memperbarui ulasan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Build WhatsApp message for order notification
     */
    private function buildWhatsAppMessage(ServiceOrder $order): string
    {
        $lines = [];
        $lines[] = "Mendapat Pesanan Layanan Jasa SIMSLIFE";
        $lines[] = "";
        $lines[] = "=== Data Pemesan ===";
        $lines[] = "Nama: {$order->customer_name}";
        $lines[] = "Telepon: {$order->customer_phone}";
        if ($order->customer_address) {
            $lines[] = "Alamat: {$order->customer_address}";
        }
        $lines[] = "";
        $lines[] = "=== Detail Pesanan ===";
        $lines[] = "Layanan: {$order->service_name}";
        $lines[] = "Harga: Rp " . number_format($order->total_price, 0, ',', '.');
        $lines[] = "No. Pesanan: {$order->formatted_order_number}";

        if ($order->booking_date) {
            $lines[] = "Jadwal: " . $order->booking_display;
        }

        if ($order->booking_note) {
            $lines[] = "Catatan: {$order->booking_note}";
        }

        $lines[] = "";
        $lines[] = "=== Metode Pembayaran ===";
        $lines[] = $order->payment_method ?? 'COD';

        return implode("\n", $lines);
    }

    /**
     * Build WhatsApp URL
     */
    private function buildWhatsAppUrl(?Merchant $merchant, string $message): string
    {
        $phone = $merchant?->whatsapp ?? $merchant?->phone ?? null;

        if ($phone) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) <= 11 && str_starts_with($phone, '0')) {
                $phone = '62' . substr($phone, 1);
            }
            return "https://wa.me/{$phone}?text=" . urlencode($message);
        }

        return '';
    }

    /**
     * Update rating summaries for jasa and merchant.
     * Uses rateable_id/rateable_type polymorphic pattern consistently.
     */
    private function updateRatingSummary(int $jasaId, int $merchantId): void
    {
        // Update jasa rating summary — polymorphic: rateable_id = jasa_id, rateable_type = Jasa::class
        $jasaRatings = Rating::where('rateable_id', $jasaId)
            ->where('rateable_type', Jasa::class)
            ->get();

        RatingSummary::updateOrCreate(
            ['rateable_id' => $jasaId, 'rateable_type' => Jasa::class],
            [
                'merchant_id' => $merchantId,
                'average_rating' => round($jasaRatings->avg('rating') ?? 0, 1),
                'total_reviews' => $jasaRatings->count(),
                'total_ratings' => $jasaRatings->count(),
                'rating_5_count' => $jasaRatings->where('rating', 5)->count(),
                'rating_4_count' => $jasaRatings->where('rating', 4)->count(),
                'rating_3_count' => $jasaRatings->where('rating', 3)->count(),
                'rating_2_count' => $jasaRatings->where('rating', 2)->count(),
                'rating_1_count' => $jasaRatings->where('rating', 1)->count(),
            ]
        );

        // Update merchant overall rating summary
        // Polymorphic: use rateable_id = merchant_id, rateable_type = 'Merchant' (no actual Merchant model class)
        $merchantRatings = Rating::where('merchant_id', $merchantId)->get();

        RatingSummary::updateOrCreate(
            ['rateable_id' => $merchantId, 'rateable_type' => 'Merchant'],
            [
                'merchant_id' => $merchantId,
                'average_rating' => round($merchantRatings->avg('rating') ?? 0, 1),
                'total_reviews' => $merchantRatings->count(),
                'total_ratings' => $merchantRatings->count(),
                'rating_5_count' => $merchantRatings->where('rating', 5)->count(),
                'rating_4_count' => $merchantRatings->where('rating', 4)->count(),
                'rating_3_count' => $merchantRatings->where('rating', 3)->count(),
                'rating_2_count' => $merchantRatings->where('rating', 2)->count(),
                'rating_1_count' => $merchantRatings->where('rating', 1)->count(),
            ]
        );
    }
}
