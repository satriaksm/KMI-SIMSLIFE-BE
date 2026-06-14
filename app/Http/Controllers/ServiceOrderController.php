<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceCompletionEvidence;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\JasaOrderItem;
use App\Models\Rating;
use App\Models\RatingSummary;
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
 * Full UMKM Jasa service order lifecycle:
 * - Create order (from langsung_pesan or from consultation)
 * - Merchant accept/reject
 * - Work evidence upload
 * - Customer confirmation
 * - Review submission
 */
class ServiceOrderController extends Controller
{
    /**
     * Create a new service order (langsung_pesan flow)
     * Creates order in BOTH orders table (for unified reporting) AND service_orders table (for backward compatibility)
     * Service-specific details are stored in jasa_order_items table
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

        $jasa = Jasa::with(['merchant', 'images'])->findOrFail($request->jasa_id);

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
            // ===== 1. Create order in orders table (UNIFIED) =====
            // NOTE: order_type = 'jasa' (jenis order utama: product/jasa)
            // order_method disimpan di jasa_order_items, BUKAN di orders
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
                'status' => 'pending',
            ]);

            // ===== 2. Create jasa_order_items (service-specific details) =====
            // order_method: mekanisme pemesanan (keranjang, booking, konsultasi)
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
            ]);

            // ===== 3. Create service_orders for backward compatibility =====
            // NOTE: service_orders.mekanisme_pemesanan adalah LEGACY, JANGAN ditulis lagi
            // order_type di orders adalah yang PRIMARY - jangan duplikasi ke service_orders
            $serviceOrder = ServiceOrder::create([
                'customer_id' => $customerId,
                'merchant_id' => $merchantId,
                'jasa_id' => $jasa->id,
                'service_name' => $jasa->title,
                'service_type' => $serviceType,
                'service_image' => $serviceImage,
                'merchant_name' => $jasa->merchant->name ?? 'UMKM',
                'total_price' => $totalPrice,
                'status' => ServiceOrder::STATUS_MENUNGGU_KONFIRMASI,
                'booking_date' => $request->booking_date,
                'booking_time' => $request->booking_time,
                'booking_note' => $request->booking_note,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_address' => ($serviceType === 'ke_rumah_pelanggan' || $serviceType === 'on_site')
                    ? $request->customer_address
                    : null,
                'payment_method' => $paymentMethod,
                'payment_status' => ServiceOrder::PAYMENT_UNPAID,
                'customer_latitude' => $request->latitude,
                'customer_longitude' => $request->longitude,
            ]);

            // Link jasa_order_items to service_order
            $jasaOrderItem->update([
                'service_order_id' => $serviceOrder->id,
            ]);

            DB::commit();

            Log::info('[ServiceOrder Create] Order created', [
                'order_id' => $order->id,
                'service_order_id' => $serviceOrder->id,
                'jasa_id' => $jasa->id,
                'merchant_id' => $merchantId,
                'payment_method' => $paymentMethod,
                'is_cod' => $isCodPayment,
            ]);

            // Build response
            // For Xendit: Frontend should call POST /api/payments/{order_id}/invoice to create Xendit invoice
            // For COD: Redirect to booking confirmation
            $responseData = [
                'success' => true,
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'service_order_id' => $serviceOrder->id,
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
     * Get WhatsApp redirect URL for a service order
     */
    public function redirectWhatsapp(Request $request, int $id)
    {
        $customerId = Auth::id();
        $order = ServiceOrder::where('id', $id)
            ->where('customer_id', $customerId)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $merchant = $order->merchant;
        $phone = $merchant?->whatsapp ?? $merchant?->phone ?? null;

        if (!$phone) {
            return ApiResponse::error('Nomor WhatsApp merchant tidak tersedia', 400);
        }

        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (!str_starts_with($phone, '62')) {
            $phone = '62' . ltrim($phone, '0');
        }

        $message = "Halo {$merchant->name}, saya telah mengajukan pesanan layanan #{$order->id}.\n";
        $message .= "Mohon untuk dapat dikonfirmasi.\n\n";
        $message .= "Terima kasih.";

        $waUrl = "https://wa.me/{$phone}?text=" . urlencode($message);

        return ApiResponse::success(['redirect_url' => $waUrl], 'success');
    }

    /**
     * Get customer service order history
     *
     * Refactored: Uses orders as primary source, jasa_order_items for service details,
     * service_orders for backward compatibility (status, review, evidences)
     */
    public function getCustomerHistory(Request $request)
    {
        $customerId = Auth::id();
        // $status = $request->get('status'); // DISABLED - frontend handles filtering
        $perPage = $request->get('per_page', 100); // Increase to 100 for client-side filtering

        // PRIMARY: Query from orders table (jasa type)
        // NOTE: Access jasas through jasaItems.jasa, NOT Order::jasa (relation doesn't exist)
        $query = Order::with([
            'merchant:id,name,slug,logo_path,segmentation_id',
            'jasaItems.jasa:id,title,image',
            'jasaItems.review.media',
            'jasaItems.completionEvidences',
            'jasaItems',
        ])
            ->where('user_id', $customerId)
            ->whereHas('jasaItems') // Orders yang punya jasa_order_items
            ->orderByDesc('created_at');

        // Filter by status - DISABLED for now, frontend will handle filtering
        // if ($status) {
        //     $ordersStatus = $this->mapFrontendStatusToOrdersStatus($status);
        //     if ($ordersStatus) {
        //         $query->where('status', $ordersStatus);
        //     } else {
        //         $query->where('status', $status);
        //     }
        // }

        $orders = $query->paginate($perPage);

        // Transform to array - merge with service_orders data for backward compatibility
        $ordersArray = $orders->toArray();
        $transformedData = collect($orders->items())->map(function ($order) {
            // Get jasa_order_item first (jasa_id ada di sini, bukan di orders)
            $jasaItem = $order->jasaItems->first();

            // Get related service_order via jasa_order_items (backward compatibility)
            $serviceOrder = $jasaItem?->serviceOrder;

            $orderArray = [];

            // ============================================================
            // PRIMARY IDs - Use these as main identifiers
            // ============================================================
            // id: alias for order_id (backward compatibility with frontend)
            $orderArray['id'] = $order->id;
            $orderArray['order_id'] = $order->id; // PRIMARY ID - Use this for updates
            $orderArray['jasa_order_item_id'] = $jasaItem?->id;
            $orderArray['service_order_id'] = $serviceOrder?->id; // Only for backward compatibility

            // ============================================================
            // Get jasa_order_item for additional data
            // ============================================================
            $jasaItem = $order->jasaItems->first();
            if ($jasaItem) {
                $orderArray['jasa_order_item_id'] = $jasaItem->id;
                $orderArray['service_type'] = $jasaItem->service_type;
                $orderArray['service_type_label'] = $jasaItem->service_type_label;
                $orderArray['booking_type'] = $jasaItem->order_method;
                $orderArray['booking_date'] = $jasaItem->booking_date;
                $orderArray['booking_time'] = $jasaItem->booking_time;
                $orderArray['tanggal'] = $jasaItem->booking_date ?? $order->tanggal; // Backward compatibility
                $orderArray['waktu'] = $jasaItem->booking_time ?? $order->waktu; // Backward compatibility
                $orderArray['booking_note'] = $jasaItem->booking_note ?? $jasaItem->note;
                $orderArray['service_location_address'] = $jasaItem->service_location_address;
            }

            // ============================================================
            // STATUS - Clear separation between orders and service_orders
            // ============================================================
            $orderArray['order_status'] = $order->status;
            $orderArray['order_status_label'] = $this->getOrdersStatusLabel($order->status ?? 'pending');
            $orderArray['service_status'] = $serviceOrder?->status;
            $orderArray['service_status_label'] = $serviceOrder?->status_label;
            $orderArray['status'] = $serviceOrder?->status ?? $order->status;
            $orderArray['status_label'] = $serviceOrder?->status_label
                ?? $this->getServiceStatusLabel($order->status);

            // ============================================================
            // Order Number
            // ============================================================
            $orderArray['order_number'] = $serviceOrder?->order_number
                ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);

            // ============================================================
            // Customer Info - dari service_order (backward compatibility)
            // ============================================================
            $orderArray['customer_name'] = $serviceOrder?->customer_name ?? $order->user?->name;
            $orderArray['customer_phone'] = $serviceOrder?->customer_phone ?? $order->user?->phone;
            $orderArray['customer_address'] = $serviceOrder?->customer_address ?? $jasaItem?->service_location_address;

            // ============================================================
            // Payment Info - Use orders.payment_status as source of truth
            // ============================================================
            $orderArray['payment_status'] = $order->payment_status ?? $serviceOrder?->payment_status;
            $orderArray['payment_method'] = $order->payment_method ?? $serviceOrder?->payment_method;
            $orderArray['payment_channel'] = $order->payment_channel ?? $order->paid_channel ?? $serviceOrder?->payment_channel;
            $orderArray['paid_channel'] = $order->paid_channel ?? $serviceOrder?->paid_channel;
            $orderArray['is_payment_completed'] = strtoupper($order->payment_status ?? '') === 'PAID' || strtoupper($order->payment_method ?? '') === 'COD';

            // ============================================================
            // Service Info - dari jasa_order_items.jasa, BUKAN dari orders.jasa
            // ============================================================
            $orderArray['total_price'] = (float) ($serviceOrder?->total_price ?? $order->total_price);
            $orderArray['service_name'] = $jasaItem?->jasa?->title;
            $orderArray['service_image'] = $serviceOrder?->service_image
                ?? ($jasaItem?->jasa?->image ? asset('storage/' . $jasaItem->jasa->image) : null);

            // ============================================================
            // Order Method - mekanisme pemesanan (PRIMARY)
            // ============================================================
            $orderArray['order_method'] = $jasaItem?->order_method;
            $orderArray['order_method_label'] = $jasaItem?->order_method_label;
            // Legacy: order_type (backward compatibility)
            $orderArray['order_type'] = $this->resolveOrderType($order, $jasaItem, $serviceOrder);

            // ============================================================
            // Review Data
            // ============================================================
            $review = $serviceOrder?->review;
            if (!$review && $jasaItem?->review) {
                $review = $jasaItem->review;
            }
            $isReviewed = $serviceOrder?->review_id !== null || $jasaItem?->is_reviewed === true;

            if ($review) {
                $reviewMedia = [];
                if ($review->media) {
                    $reviewMedia = $review->media->map(function ($media) {
                        $arr = $media->toArray();
                        // Use media_url (full URL) or construct from file_path
                        $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                        return $arr;
                    })->toArray();
                }
                $orderArray['review'] = array_merge($review->toArray(), ['media' => $reviewMedia]);
            } else {
                $orderArray['review'] = null;
            }
            $orderArray['is_reviewed'] = $isReviewed;

            // ============================================================
            // Completion Evidences
            // ============================================================
            if ($serviceOrder && $serviceOrder->completionEvidences && $serviceOrder->completionEvidences->count() > 0) {
                $evidences = $serviceOrder->completionEvidences->map(function ($evidence) {
                    $arr = $evidence->toArray();
                    // Use media_url (full URL) or construct from file_path
                    $arr['file_url'] = $evidence->media_url ?? ($evidence->file_path ? asset('storage/' . $evidence->file_path) : null);
                    return $arr;
                })->toArray();
                $orderArray['completion_evidences'] = $evidences;
            } else {
                $orderArray['completion_evidences'] = [];
            }

            // ============================================================
            // Merchant Info
            // ============================================================
            $orderArray['merchant'] = [
                'id' => $order->merchant_id,
                'name' => $order->merchant?->name,
                'slug' => $order->merchant?->slug,
            ];

            // ============================================================
            // Jasa Info - ambil dari jasa_order_items, bukan dari orders
            // ============================================================
            $orderArray['jasa'] = [
                'id' => $jasaItem?->jasa_id,
                'title' => $jasaItem?->jasa?->title,
                'image' => $jasaItem?->jasa?->image,
                'slug' => $jasaItem?->jasa?->slug,
            ];

            // ============================================================
            // Timestamps
            // ============================================================
            $orderArray['created_at'] = $order->created_at?->toIso8601String();
            $orderArray['updated_at'] = $order->updated_at?->toIso8601String();

            return $orderArray;
        })->toArray();

        $ordersArray['data'] = $transformedData;

        return ApiResponse::success($ordersArray, 'success');
    }

    /**
     * Get single service order detail (for customer)
     *
     * Refactored: Uses orders as primary source, jasa_order_items for service details,
     * service_orders for backward compatibility (status, review, evidences)
     */
    public function getCustomerOrderDetail(Request $request, int $id)
    {
        $customerId = Auth::id();

        try {
            // Try to find order in orders table first
            // NOTE: 'address' is NOT a DB column on merchants — it's a computed accessor.
            // We must eager-load primaryAddress relationship instead.
            // NOTE: jasa_id ada di jasa_order_items, BUKAN di orders
            $order = Order::with([
                'merchant:id,name,slug,logo_path,segmentation_id,phone',
                'merchant.primaryAddress',
                'payment',
                'jasaItems.jasa:id,title,image,slug',
                'jasaItems.review.media',
                'jasaItems.completionEvidences',
            ])
                ->where('user_id', $customerId)
                ->whereHas('jasaItems') // Orders yang punya jasa_order_items
                ->find($id);

            if (!$order) {
                // Fallback: check if this is an old service_order without orders entry
                $serviceOrder = ServiceOrder::with([
                    'jasa',
                    'merchant:id,name,slug,logo_path,segmentation_id,phone',
                    'review.media',
                    'completionEvidences',
                    'jasa.ratingSummary',
                ])
                    ->where('customer_id', $customerId)
                    ->find($id);

                if (!$serviceOrder) {
                    return ApiResponse::error('Pesanan tidak ditemukan', 404);
                }

                $transformedOrder = $this->transformServiceOrderForCustomer($serviceOrder);
                return ApiResponse::success($transformedOrder, 'success');
            }

            $jasaItem = $order->jasaItems->first();
            $serviceOrder = $jasaItem?->serviceOrder;

            $transformedOrder = $this->transformOrderForCustomer($order, $serviceOrder, $jasaItem);

            return ApiResponse::success($transformedOrder, 'success');
        } catch (\Exception $e) {
            Log::error('[ServiceOrderController] getCustomerOrderDetail failed', [
                'id' => $id,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ApiResponse::error('Gagal memuat detail pesanan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Transform order (from orders table) for customer-facing responses
     */
    private function transformOrderForCustomer(Order $order, ?ServiceOrder $serviceOrder, ?JasaOrderItem $jasaItem): array
    {
        $orderArray = $order->toArray();

        // Get merchant address — use primaryAddress (eager-loaded, NOT DB column 'address')
        $primaryAddress = $order->merchant?->primaryAddress;
        $merchantAddress = $primaryAddress?->detail ?? null;

        // Determine display address based on service type
        $serviceType = $jasaItem?->service_type ?? $serviceOrder?->service_type ?? null;
        $displayAddress = match ($serviceType) {
            'online' => 'Online',
            'di_tempat_umkm', 'at_location' => $merchantAddress ?? 'Lokasi UMKM',
            default => $order->alamat ?? $merchantAddress ?? '-',
        };

        // Get status from service_order if available
        $status = $serviceOrder?->status ?? $order->status;
        $statusLabel = $serviceOrder?->status_label ?? ucfirst(str_replace('_', ' ', $order->status ?? 'unknown'));

        // Payment Info - Use orders.payment_status as source of truth
        // Also read from Payment relation if available
        $paymentStatus = $order->payment_status ?? $serviceOrder?->payment_status;
        $paymentMethod = $order->payment_method ?? $serviceOrder?->payment_method;
        $paymentChannel = $order->payment_channel ?? $order->paid_channel ?? $serviceOrder?->payment_channel;
        $totalPrice = (float) ($serviceOrder?->total_price ?? $order->total_price);

        // Read from Payment relation (Xendit channel is stored here after webhook)
        $paymentRelation = $order->payment;
        if ($paymentRelation) {
            if ($paymentRelation->status === 'PAID') {
                $paymentStatus = 'PAID';
            }
            if ($paymentRelation->paid_channel) {
                $paymentChannel = $paymentRelation->paid_channel;
            }
        }

        // Payment method display mapping
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
            ?? $paymentMethod
            ?? '-';

        // Payment status display mapping
        $paymentStatusLabels = [
            'UNPAID' => 'Belum Bayar',
            'PAID' => 'Lunas / Sudah Dibayar',
            'WAITING_CONFIRMATION' => 'Menunggu Konfirmasi',
            'PENDING' => 'Menunggu Pembayaran',
        ];
        $paymentStatusDisplay = $paymentStatusLabels[strtoupper($paymentStatus ?? '')] ?? $paymentStatus ?? '-';

        // Is payment completed
        $isPaymentCompleted = strtoupper($paymentMethod ?? '') === 'COD'
            || strtoupper($paymentStatus ?? '') === 'PAID';

        // Get review data
        $review = $serviceOrder?->review;
        if (!$review && $jasaItem?->review) {
            $review = $jasaItem->review;
        }

        // Check if order is reviewed
        $isReviewed = $serviceOrder?->review_id !== null || $jasaItem?->is_reviewed === true;

        // Get completion evidences
        $completionEvidences = $serviceOrder?->completionEvidences ?? collect();
        if ($completionEvidences->isEmpty() && $jasaItem) {
            $completionEvidences = $jasaItem->completionEvidences ?? collect();
        }

        return [
            'id' => $order->id,
            'service_order_id' => $serviceOrder?->id,
            'jasa_order_item_id' => $jasaItem?->id,
            'order_number' => $serviceOrder?->order_number ?? 'SO-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            // Customer info - dari service_order (backward compatibility)
            'customer_name' => $serviceOrder?->customer_name ?? $order->user?->name,
            'customer_phone' => $serviceOrder?->customer_phone ?? $order->user?->phone,
            'customer_address' => $serviceOrder?->customer_address ?? $jasaItem?->service_location_address,
            'booking_date' => $jasaItem?->booking_date,
            'booking_time' => $jasaItem?->booking_time,
            'payment_method' => $paymentMethod,
            'payment_method_display' => $paymentMethodDisplay,
            'payment_status' => $paymentStatus,
            'payment_status_display' => $paymentStatusDisplay,
            'payment_channel' => $paymentChannel,
            'paid_channel' => $order->paid_channel ?? $serviceOrder?->paid_channel,
            'is_payment_completed' => $isPaymentCompleted,
            // Payment relation data (from payments table)
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
            // Service info - dari jasa_order_items.jasa, BUKAN dari orders.jasa
            'service_name' => $jasaItem?->jasa?->title ?? $serviceOrder?->service_name,
            'service_image' => $serviceOrder?->service_image
                ?? ($jasaItem?->jasa?->image ? asset('storage/' . $jasaItem->jasa->image) : null),
            // order_method: mekanisme pemesanan (PRIMARY) - gunakan jasa_order_items.order_method
            'order_method' => $jasaItem?->order_method,
            'order_method_label' => $jasaItem?->order_method_label,
            // Legacy: order_type, mekanisme_pemesanan (backward compatibility - akan dihapus nanti)
            'order_type' => $this->resolveOrderType($order, $jasaItem, $serviceOrder),
            'mekanisme_pemesanan' => $jasaItem?->order_method,
            'status' => $status,
            'status_label' => $statusLabel,
            'booking_note' => $jasaItem?->booking_note ?? $jasaItem?->note,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            // Merchant info
            'merchant' => [
                'id' => $orderArray['merchant']['id'] ?? null,
                'name' => $orderArray['merchant']['name'] ?? null,
                'slug' => $orderArray['merchant']['slug'] ?? null,
                'address' => $merchantAddress,
            ],
            // Jasa info - dari jasa_order_items.jasa, BUKAN dari orders.jasa
            'jasa' => [
                'id' => $jasaItem?->jasa_id,
                'title' => $jasaItem?->jasa?->title ?? null,
                'image' => $jasaItem?->jasa?->image ?? null,
                'cover_image' => $jasaItem?->jasa?->image ?? null,
            ],
            // Review data (backward compatibility)
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
            // Completion evidences (backward compatibility)
            'completion_evidences' => $completionEvidences->map(function ($evidence) {
                $arr = $evidence->toArray();
                if (!isset($arr['file_url']) || empty($arr['file_url'])) {
                    $arr['file_url'] = $evidence->file_url;
                }
                return $arr;
            })->toArray(),
            // Display helpers
            'display_address' => $displayAddress,
            'address_label' => match ($serviceType) {
                'online' => 'Lokasi',
                'di_tempat_umkm', 'at_location' => 'Lokasi UMKM',
                default => 'Alamat',
            },
        ];
    }

    /**
     * Transform service order for customer-facing responses
     */
    private function transformServiceOrderForCustomer(ServiceOrder $order): array
    {
        $orderArray = $order->toArray();

        // Get merchant address — use primaryAddress (eager-loaded, NOT DB column 'address')
        $primaryAddress = $order->merchant?->primaryAddress;
        $merchantAddress = $primaryAddress?->detail ?? null;

        // Determine display address based on service type
        $displayAddress = match ($order->service_type) {
            'online' => 'Online',
            'di_tempat_umkm', 'at_location' => $merchantAddress ?? 'Lokasi UMKM',
            default => $order->customer_address ?? $merchantAddress ?? '-',
        };

        // Payment method display mapping
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
        $paymentMethodDisplay = $paymentMethodLabels[strtoupper($order->payment_method ?? '')]
            ?? $paymentMethodLabels[strtolower($order->payment_method ?? '')]
            ?? $order->payment_method
            ?? '-';

        // Payment status display mapping
        $paymentStatusLabels = [
            'UNPAID' => 'Belum Bayar',
            'PAID' => 'Lunas / Sudah Dibayar',
            'WAITING_CONFIRMATION' => 'Menunggu Konfirmasi',
            'PENDING' => 'Menunggu Pembayaran',
        ];
        $paymentStatusDisplay = $paymentStatusLabels[strtoupper($order->payment_status ?? '')] ?? $order->payment_status ?? '-';

        // Is payment completed
        $isPaymentCompleted = strtoupper($order->payment_method ?? '') === 'COD'
            || strtoupper($order->payment_status ?? '') === 'PAID';

        // Check if order is reviewed (for old service_orders without order entry)
        $isReviewed = $order->review_id !== null;

        // Resolve order_type: Check if there's a related orders entry
        // 1. First, try to get order_type from related orders via jasa_order_items
        $jasaItem = $order->jasaOrderItems->first();
        $relatedOrder = $jasaItem?->order;
        $resolvedOrderType = $relatedOrder?->order_type
            ?? $this->mapMekanismeToOrderType($order->mekanisme_pemesanan);

        return [
            'id' => $order->id,
            'order_number' => $order->formatted_order_number,
            'jasa_order_item_id' => $jasaItem?->id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
            'booking_date' => $order->booking_date,
            'booking_time' => $order->booking_time,
            'payment_method' => $order->payment_method,
            'payment_method_display' => $paymentMethodDisplay,
            'payment_status' => $order->payment_status,
            'payment_status_display' => $paymentStatusDisplay,
            'payment_channel' => $order->payment_channel,
            'paid_channel' => $order->paid_channel,
            'is_payment_completed' => $isPaymentCompleted,
            'total_price' => (float) $order->total_price,
            'service_type' => $order->service_type,
            'service_name' => $order->service_name,
            'service_image' => $order->service_image,
            // order_type: from orders.order_type (PRIMARY), fallback to mekanisme_pemesanan mapping
            'order_type' => $resolvedOrderType,
            // Legacy: mekanisme_pemesanan (backward compatibility)
            'mekanisme_pemesanan' => $order->mekanisme_pemesanan,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'booking_note' => $order->booking_note,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            // Merchant info
            'merchant' => [
                'id' => $orderArray['merchant']['id'] ?? null,
                'name' => $orderArray['merchant']['name'] ?? null,
                'slug' => $orderArray['merchant']['slug'] ?? null,
                'address' => $merchantAddress,
            ],
            // Jasa info
            'jasa' => [
                'id' => $orderArray['jasa']['id'] ?? null,
                'title' => $orderArray['jasa']['title'] ?? null,
                'image' => $orderArray['jasa']['image'] ?? null,
                'cover_image' => $orderArray['jasa']['image'] ?? null, // Alias for backward compatibility
            ],
            // Review data
            'review' => $order->review ? array_merge($order->review->toArray(), [
                'media' => $order->review->media->map(function ($media) {
                    $arr = $media->toArray();
                    if (!isset($arr['file_url']) || $arr['file_url'] === '') {
                        $arr['file_url'] = $media->file_url;
                    }
                    return $arr;
                })->toArray()
            ]) : null,
            'is_reviewed' => $isReviewed,
            // Display helpers
            'display_address' => $displayAddress,
            'address_label' => match ($order->service_type) {
                'online' => 'Lokasi',
                'di_tempat_umkm', 'at_location' => 'Lokasi UMKM',
                default => 'Alamat',
            },
        ];
    }

    /**
     * Customer confirms that work is completed (after seeing evidence).
     *
     * Refactored: Sync confirmation between orders and service_orders tables
     */
    public function confirmCompleted(Request $request, int $id)
    {
        $customerId = Auth::id();

        // Try to find order in orders table first
        // NOTE: order_type BUKAN 'jasa' - itu mekanisme pemesanan lama
        $order = Order::where('user_id', $customerId)
            ->whereHas('jasaItems') // Orders yang punya jasa_order_items
            ->find($id);

        $serviceOrder = null;
        if ($order) {
            // Get related service_order
            $jasaItem = $order->jasaItems->first();
            if ($jasaItem && $jasaItem->service_order_id) {
                $serviceOrder = ServiceOrder::find($jasaItem->service_order_id);
            }
        } else {
            // Fallback: find directly in service_orders
            $serviceOrder = ServiceOrder::where('customer_id', $customerId)
                ->where('id', $id)
                ->first();

            if (!$serviceOrder) {
                return ApiResponse::error('Pesanan tidak ditemukan', 404);
            }
        }

        // Check if order is in correct status
        $currentStatus = $serviceOrder?->status ?? $order?->status;
        if ($currentStatus !== ServiceOrder::STATUS_MENUNGGU_SELESAI) {
            return ApiResponse::error(
                'Pesanan tidak dapat dikonfirmasi. Status saat ini: ' . ($serviceOrder?->statusLabel ?? 'unknown'),
                400
            );
        }

        // Check that there is at least one completion evidence
        $hasEvidence = $serviceOrder && $serviceOrder->completionEvidences()->count() > 0;
        if (!$hasEvidence) {
            // Check if evidence is in jasa_order_items
            if ($order) {
                $jasaItem = $order->jasaItems->first();
                $hasEvidence = $jasaItem && $jasaItem->completionEvidences()->count() > 0;
            }
        }

        if (!$hasEvidence) {
            return ApiResponse::error('Bukti pengerjaan belum tersedia', 400);
        }

        DB::beginTransaction();
        try {
            // Confirm in service_orders
            if ($serviceOrder) {
                $serviceOrder->confirmCompleted();
            }

            // Also update orders table
            if ($order) {
                $order->update(['status' => 'selesai']);

                // Update jasa_order_items
                $jasaItem = $order->jasaItems->first();
                if ($jasaItem) {
                    $jasaItem->update([
                        'customer_confirmed' => true,
                        'customer_confirmed_at' => now(),
                    ]);
                }
            }

            DB::commit();

            // Return updated data
            if ($serviceOrder) {
                return ApiResponse::success($serviceOrder->fresh(['completionEvidences']), 'Pesanan berhasil dikonfirmasi selesai. Terima kasih!');
            }

            return ApiResponse::success($order->fresh(['jasaItems.completionEvidences']), 'Pesanan berhasil dikonfirmasi selesai. Terima kasih!');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Gagal mengkonfirmasi pesanan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get human-readable status label for orders.status
     * orders.status ENUM: pending, proses, selesai, batal
     */
    private function getOrdersStatusLabel(string $status): string
    {
        $labels = [
            'pending' => 'Menunggu Konfirmasi',
            'proses' => 'Sedang Diproses',
            'selesai' => 'Selesai',
            'batal' => 'Dibatalkan',
        ];

        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Get service-style status label for orders.status
     * Used when service_orders doesn't exist but we need display label
     * Maps orders.status to service_orders style labels
     */
    private function getServiceStatusLabel(string $ordersStatus): string
    {
        $mapping = [
            'pending' => 'Menunggu Konfirmasi Merchant',
            'proses' => 'Sedang Diproses',
            'selesai' => 'Selesai',
            'batal' => 'Dibatalkan',
        ];

        return $mapping[$ordersStatus] ?? ucfirst(str_replace('_', ' ', $ordersStatus));
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
     * 3. service_orders.mekanisme_pemesanan (legacy fallback)
     */
    private function resolveOrderType(?Order $order, ?JasaOrderItem $jasaItem, ?ServiceOrder $serviceOrder): ?string
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

        // 3. Check service_orders.mekanisme_pemesanan (LEGACY fallback)
        if ($serviceOrder && !empty($serviceOrder->mekanisme_pemesanan)) {
            return $this->mapMekanismeToOrderMethod($serviceOrder->mekanisme_pemesanan);
        }

        // 4. Fallback to 'keranjang' (FE format)
        return 'keranjang';
    }

    /**
     * Get merchant service order history
     *
     * Refactored: Uses orders as primary source, jasa_order_items for service details,
     * service_orders for backward compatibility (status, review, evidences)
     */
    public function getMerchantHistory(Request $request, Merchant $merchant)
    {
        // Authorize: current user must own this merchant
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses ke merchant ini', 403);
        }

        // VALIDATION: Only UMKM Jasa can have service orders
        if ($merchant->segmentation_id !== 3) {
            return ApiResponse::error('Merchant ini tidak memiliki layanan jasa', 400);
        }

        // $status = $request->get('status'); // DISABLED - frontend handles filtering
        $perPage = $request->get('per_page', 100); // Increase to 100 for client-side filtering

        // PRIMARY: Query from orders table (jasa type)
        // NOTE: jasa_id ada di jasa_order_items, BUKAN di orders
        $query = Order::with([
            'user:id,name,phone',
            'jasaItems.jasa:id,title,image,slug',
            'jasaItems.review.media',
            'jasaItems.completionEvidences',
            'jasaItems.serviceOrder',
        ])
            ->where('merchant_id', $merchant->id)
            ->whereHas('jasaItems') // Orders yang punya jasa_order_items
            ->orderByDesc('created_at');

        // Filter by status - DISABLED for now, frontend will handle filtering
        // TODO: Re-enable with proper status mapping when frontend filter is stable
        // if ($status) {
        //     $ordersStatus = $this->mapFrontendStatusToOrdersStatus($status);
        //     if ($ordersStatus) {
        //         $query->where('status', $ordersStatus);
        //     } else {
        //         $query->where('status', $status);
        //     }
        // }

        $orders = $query->paginate($perPage);

        // Transform orders - merge with service_orders data for backward compatibility
        $ordersArray = $orders->toArray();
        $transformedData = collect($orders->items())->map(function ($order) {
            // Get jasa_order_item first (jasa_id ada di sini, bukan di orders)
            $jasaItem = $order->jasaItems->first();

            // Get related service_order via jasa_order_items (backward compatibility)
            $serviceOrder = $jasaItem?->serviceOrder;

            $orderArray = [];

            // ============================================================
            // PRIMARY IDs - Use these as main identifiers
            // ============================================================
            // id: alias for order_id (backward compatibility with frontend)
            $orderArray['id'] = $order->id;
            $orderArray['order_id'] = $order->id; // PRIMARY ID - Use this for updates
            $orderArray['jasa_order_item_id'] = null;
            $orderArray['service_order_id'] = $serviceOrder?->id; // Only for backward compatibility

            // ============================================================
            // Get jasa_order_item for additional data
            // ============================================================
            $jasaItem = $order->jasaItems->first();
            if ($jasaItem) {
                $orderArray['jasa_order_item_id'] = $jasaItem->id;
                $orderArray['service_type'] = $jasaItem->service_type;
                $orderArray['service_type_label'] = $jasaItem->service_type_label;
                $orderArray['booking_type'] = $jasaItem->order_method;
                $orderArray['booking_date'] = $jasaItem->booking_date;
                $orderArray['booking_time'] = $jasaItem->booking_time;
                $orderArray['booking_note'] = $jasaItem->booking_note ?? $jasaItem->note;
                $orderArray['service_location_address'] = $jasaItem->service_location_address;
                $orderArray['customer_latitude'] = $jasaItem->customer_latitude ?? null;
                $orderArray['customer_longitude'] = $jasaItem->customer_longitude ?? null;
            }

            // ============================================================
            // STATUS - Use orders table as primary source
            // ============================================================
            // order_status is from orders table (primary source for filtering)
            $orderArray['order_status'] = $order->status;
            $orderArray['order_status_label'] = $this->getOrdersStatusLabel($order->status ?? 'pending');

            // display_status uses orders status as primary, with service_orders as fallback
            $orderArray['status'] = $order->status;
            $orderArray['status_label'] = $this->getServiceStatusLabel($order->status);
            // Keep service_status for backward compatibility
            $orderArray['service_status'] = $serviceOrder?->status;
            $orderArray['service_status_label'] = $serviceOrder?->status_label;

            // ============================================================
            // Order Number
            // ============================================================
            $orderArray['order_number'] = $serviceOrder?->order_number
                ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);

            // ============================================================
            // Customer Info - dari service_order (backward compatibility)
            // ============================================================
            $orderArray['customer_name'] = $serviceOrder?->customer_name ?? $order->user?->name;
            $orderArray['customer_phone'] = $serviceOrder?->customer_phone ?? $order->user?->phone;
            $orderArray['customer_address'] = $serviceOrder?->customer_address ?? $jasaItem?->service_location_address;
            $orderArray['customer_latitude'] = $jasaItem?->customer_latitude ?? null;
            $orderArray['customer_longitude'] = $jasaItem?->customer_longitude ?? null;

            // ============================================================
            // Payment Info - Use orders.payment_status as primary source
            // ============================================================
            $orderArray['payment_status'] = $order->payment_status;
            $orderArray['payment_method'] = $order->payment_method;
            $orderArray['payment_channel'] = $order->payment_channel ?? $order->paid_channel;
            $orderArray['paid_channel'] = $order->paid_channel;

            // ============================================================
            // Service Info - dari jasa_order_items.jasa, BUKAN dari orders.jasa
            // ============================================================
            $orderArray['total_price'] = (float) $order->total_price;
            $orderArray['service_name'] = $jasaItem?->jasa?->title;
            $orderArray['service_image'] = $jasaItem?->jasa?->image ? asset('storage/' . $jasaItem->jasa->image) : null;
            // order_type: mekanisme pemesanan (PRIMARY)
            $orderArray['order_type'] = $this->resolveOrderType($order, $jasaItem, $serviceOrder);
            $orderArray['completion_note'] = $jasaItem?->completion_note ?? $serviceOrder?->completion_note;
            $orderArray['rejection_reason'] = $jasaItem?->rejection_reason ?? $serviceOrder?->rejection_reason;

            // ============================================================
            // Completion Evidences - Use jasa_order_items as primary
            // ============================================================
            $completionEvidences = $jasaItem?->completionEvidences ?? collect();
            if ($completionEvidences->isEmpty() && $serviceOrder) {
                $completionEvidences = $serviceOrder->completionEvidences ?? collect();
            }

            // ============================================================
            // Review Data - Use jasa_order_items as primary
            // ============================================================
            $review = $jasaItem?->review;
            if (!$review) {
                $review = $serviceOrder?->review;
            }
            $isReviewed = $jasaItem?->is_reviewed === true || $serviceOrder?->review_id !== null;

            if ($review) {
                $reviewMedia = [];
                if ($review->media) {
                    $reviewMedia = $review->media->map(function ($media) {
                        $arr = $media->toArray();
                        // Use media_url (full URL) or construct from file_path
                        $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                        return $arr;
                    })->toArray();
                }
                $orderArray['review'] = array_merge($review->toArray(), ['media' => $reviewMedia]);
            } else {
                $orderArray['review'] = null;
            }
            $orderArray['is_reviewed'] = $isReviewed;

            // ============================================================
            // Completion Evidences - Transform with proper URLs
            // ============================================================
            $orderArray['completion_evidences'] = $completionEvidences->map(function ($evidence) {
                $arr = $evidence->toArray();
                // Use media_url (full URL) or construct from file_path
                $arr['file_url'] = $evidence->media_url ?? ($evidence->file_path ? asset('storage/' . $evidence->file_path) : null);
                return $arr;
            })->toArray();

            // ============================================================
            // Debug logging
            // ============================================================
            Log::info('[Merchant History Mapping]', [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'review_exists' => !is_null($review),
                'review_from' => $review ? ($jasaItem?->review ? 'jasa_item' : 'service_order') : null,
                'evidence_count' => count($completionEvidences ?? []),
                'evidence_from' => $jasaItem && $jasaItem->completionEvidences?->isNotEmpty() ? 'jasa_item' : ($serviceOrder && $serviceOrder->completionEvidences?->isNotEmpty() ? 'service_order' : null),
            ]);

            // ============================================================
            // Merchant Info
            // ============================================================
            $orderArray['merchant'] = [
                'id' => $order->merchant_id,
                'name' => $order->merchant?->name,
            ];

            // ============================================================
            // Jasa Info - ambil dari jasa_order_items, bukan dari orders
            // ============================================================
            $orderArray['jasa'] = [
                'id' => $jasaItem?->jasa_id,
                'title' => $jasaItem?->jasa?->title,
                'image' => $jasaItem?->jasa?->image,
            ];

            // ============================================================
            // Customer User Info
            // ============================================================
            $orderArray['customer'] = [
                'id' => $order->user_id,
                'name' => $order->user?->name,
                'phone' => $order->user?->phone,
            ];

            // ============================================================
            // Timestamps
            // ============================================================
            $orderArray['created_at'] = $order->created_at?->toIso8601String();
            $orderArray['updated_at'] = $order->updated_at?->toIso8601String();

            return $orderArray;
        })->toArray();

        $ordersArray['data'] = $transformedData;

        return ApiResponse::success($ordersArray, 'success');
    }

    /**
     * Map orders.status to service_orders.status format
     * orders.status: pending, proses, selesai, batal
     * service_orders.status: menunggu_konfirmasi_merchant, diterima, ditolak, layanan_dikerjakan, menunggu_konfirmasi_selesai, selesai
     */
    private function mapOrdersStatusToServiceStatus(string $ordersStatus): string
    {
        $mapping = [
            'pending' => 'menunggu_konfirmasi_merchant',
            'proses' => 'diterima', // In process = accepted
            'selesai' => 'selesai',
            'batal' => 'ditolak',
        ];

        return $mapping[$ordersStatus] ?? 'menunggu_konfirmasi_merchant';
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

        // Build query from orders table
        // NOTE: jasa_id ada di jasa_order_items, BUKAN di orders
        $query = Order::with([
            'user:id,name,phone',
            'jasaItems.jasa:id,title,image,slug',
            'jasaItems.review.media',
            'jasaItems.completionEvidences',
            'jasaItems.serviceOrder',
        ])
            ->where('merchant_id', $merchant->id)
            ->whereHas('jasaItems')
            ->orderByDesc('created_at');

        // Filter by order_type if provided (jasa, product - primary type)
        if ($request->has('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        // Filter by status if provided
        if ($request->has('status')) {
            $status = $request->get('status');
            $query->where('status', $status);
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
            $jasaItem = $order->jasaItems->first();
            $serviceOrder = $jasaItem?->serviceOrder;

            // Use service_orders.status if available, otherwise map from orders.status
            $mappedServiceStatus = $serviceOrder?->status
                ?? $this->mapOrdersStatusToServiceStatus($order->status);

            return [
                // IDs
                'id' => $order->id,
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'service_order_id' => $serviceOrder?->id,

                // Order Number
                'order_number' => $serviceOrder?->order_number ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'formatted_order_number' => $serviceOrder?->formatted_order_number ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),

                // Status (from orders table as primary)
                'order_status' => $order->status,
                'order_status_label' => $this->getOrdersStatusLabel($order->status ?? 'pending'),
                'status' => $order->status, // Original orders table status
                'status_label' => $this->getServiceStatusLabel($order->status),
                // Mapped service status for frontend filter (always populated)
                'service_status' => $mappedServiceStatus,
                'service_status_label' => ServiceOrder::getStatusLabelStatic($mappedServiceStatus),
                'service_status_label' => $serviceOrder?->status_label,

                // Customer Info
                'customer_name' => $serviceOrder?->customer_name ?? $order->user?->name,
                'customer_phone' => $serviceOrder?->customer_phone ?? $order->user?->phone,
                'customer_address' => $serviceOrder?->customer_address ?? $jasaItem?->service_location_address,
                'customer' => [
                    'id' => $order->user_id,
                    'name' => $order->user?->name,
                    'phone' => $order->user?->phone,
                ],

                // Payment Info
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'payment_channel' => $order->payment_channel ?? $order->paid_channel,

                // Service/Jasa Info
                'service_name' => $jasaItem?->jasa?->title ?? $serviceOrder?->service_name,
                'service_image' => $jasaItem?->jasa?->image ? asset('storage/' . $jasaItem->jasa->image) : null,
                'service_type' => $jasaItem?->service_type ?? $serviceOrder?->service_type,
                'order_type' => $order->order_type, // Legacy: mechanism (jasa/product)
                'booking_type' => $jasaItem?->order_method,

                // Booking Info
                'booking_date' => $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time,
                'booking_note' => $jasaItem?->booking_note ?? $jasaItem?->note,
                'service_location_address' => $jasaItem?->service_location_address,

                // Pricing
                'total_price' => (float) ($order->total_price ?? $serviceOrder?->total_price ?? 0),

                // Completion
                'completion_note' => $jasaItem?->completion_note ?? $serviceOrder?->completion_note,
                'rejection_reason' => $jasaItem?->rejection_reason ?? $serviceOrder?->rejection_reason,

                // Review (from jasa_order_items as primary)
                'review' => $jasaItem?->review ? array_merge($jasaItem->review->toArray(), [
                    'media' => $jasaItem->review->media->map(function ($media) {
                        $arr = $media->toArray();
                        $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                        return $arr;
                    })->toArray()
                ]) : null,
                'is_reviewed' => $jasaItem?->is_reviewed === true || $serviceOrder?->review_id !== null,

                // Completion Evidences
                'completion_evidences' => $jasaItem?->completionEvidences->map(function ($evidence) {
                    $arr = $evidence->toArray();
                    $arr['file_url'] = $evidence->media_url ?? ($evidence->file_path ? asset('storage/' . $evidence->file_path) : null);
                    return $arr;
                })->toArray() ?? [],

                // Timestamps
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),

                // Merchant Info (for reference)
                'merchant' => [
                    'id' => $order->merchant_id,
                    'name' => $order->merchant?->name,
                ],

                // Jasa Info
                'jasa' => [
                    'id' => $jasaItem?->jasa_id,
                    'title' => $jasaItem?->jasa?->title,
                    'image' => $jasaItem?->jasa?->image,
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

        // Try to find order in orders table first
        // NOTE: jasa_id ada di jasa_order_items, BUKAN di orders
        $order = Order::with([
            'user:id,name,phone',
            'jasaItems.jasa:id,title,image,slug',
            'jasaItems.review.media',
            'jasaItems.completionEvidences',
            'jasaItems.serviceOrder',
        ])
            ->where('merchant_id', $merchant->id)
            ->whereHas('jasaItems') // Orders yang punya jasa_order_items
            ->find($id);

        if (!$order) {
            // Fallback: check if this is an old service_order without orders entry
            $serviceOrder = ServiceOrder::with([
                'jasa',
                'customer:id,name,phone',
                'review.media',
                'completionEvidences',
            ])
                ->where('merchant_id', $merchant->id)
                ->where('id', $id)
                ->first();

            if (!$serviceOrder) {
                return ApiResponse::error('Pesanan tidak ditemukan', 404);
            }

            return ApiResponse::success($serviceOrder, 'success');
        }

        // Get related service_order for backward compatibility
        $jasaItem = $order->jasaItems->first();
        $serviceOrder = $jasaItem?->serviceOrder;

        // Transform order data for merchant view
        $transformedOrder = $this->transformOrderForMerchant($order, $serviceOrder, $jasaItem);

        // Debug logging
        Log::info('[Merchant Order Detail Mapping]', [
            'order_id' => $order->id,
            'jasa_order_item_id' => $jasaItem?->id,
            'review_exists' => !is_null($transformedOrder['review']),
            'evidence_count' => count($transformedOrder['completion_evidences'] ?? []),
        ]);

        return ApiResponse::success($transformedOrder, 'success');
    }

    /**
     * Transform order (from orders table) for merchant-facing responses
     */
    private function transformOrderForMerchant(Order $order, ?ServiceOrder $serviceOrder, ?JasaOrderItem $jasaItem): array
    {
        $orderArray = $order->toArray();

        // STATUS - Use orders table as primary source
        $status = $order->status;
        $statusLabel = $this->getServiceStatusLabel($order->status);

        // PAYMENT - Use orders table as primary source
        $paymentStatus = $order->payment_status;
        $paymentMethod = $order->payment_method;
        $totalPrice = (float) $order->total_price;

        // COMPLETION EVIDENCES - Use jasa_order_items as primary
        $completionEvidences = $jasaItem?->completionEvidences ?? collect();
        if ($completionEvidences->isEmpty() && $serviceOrder) {
            $completionEvidences = $serviceOrder->completionEvidences ?? collect();
        }

        // REVIEW - Use jasa_order_items as primary
        $review = $jasaItem?->review;
        if (!$review) {
            $review = $serviceOrder?->review;
        }
        $isReviewed = $jasaItem?->is_reviewed === true || $serviceOrder?->review_id !== null;

        return [
            'id' => $order->id,
            'service_order_id' => $serviceOrder?->id, // Only for backward compatibility
            'jasa_order_item_id' => $jasaItem?->id,
            'order_number' => $serviceOrder?->order_number ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            // Customer Info - dari service_order (backward compatibility)
            'customer_name' => $serviceOrder?->customer_name ?? $order->user?->name,
            'customer_phone' => $serviceOrder?->customer_phone ?? $order->user?->phone,
            'customer_address' => $serviceOrder?->customer_address ?? $jasaItem?->service_location_address,
            'customer_latitude' => $jasaItem?->customer_latitude ?? null,
            'customer_longitude' => $jasaItem?->customer_longitude ?? null,
            // Booking Info - from jasa_order_items
            'booking_date' => $jasaItem?->booking_date,
            'booking_time' => $jasaItem?->booking_time,
            'booking_note' => $jasaItem?->booking_note ?? $jasaItem?->note,
            // Payment Info - from orders table as primary
            'payment_method' => $paymentMethod,
            'payment_channel' => $order->payment_channel ?? $order->paid_channel,
            'payment_status' => $paymentStatus,
            'total_price' => $totalPrice,
            // Service Info - dari jasa_order_items.jasa, BUKAN dari orders.jasa
            'service_type' => $jasaItem?->service_type ?? $serviceOrder?->service_type,
            'service_name' => $jasaItem?->jasa?->title ?? $serviceOrder?->service_name,
            'service_image' => $jasaItem?->jasa?->image ? asset('storage/' . $jasaItem->jasa->image) : null,
            // order_type: mekanisme pemesanan (PRIMARY)
            'order_type' => $this->resolveOrderType($order, $jasaItem, $serviceOrder),
            // Legacy: mekanisme_pemesanan (backward compatibility)
            'mekanisme_pemesanan' => $jasaItem?->order_method,
            // Status - from orders table as primary
            'status' => $status,
            'status_label' => $statusLabel,
            // Notes - from jasa_order_items as primary
            'completion_note' => $jasaItem?->completion_note ?? $serviceOrder?->completion_note,
            'rejection_reason' => $jasaItem?->rejection_reason ?? $serviceOrder?->rejection_reason,
            // Timestamps
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            // Customer user info
            'customer' => [
                'id' => $orderArray['user']['id'] ?? null,
                'name' => $orderArray['user']['name'] ?? null,
                'phone' => $orderArray['user']['phone'] ?? null,
            ],
            // Jasa info - dari jasa_order_items.jasa, BUKAN dari orders.jasa
            'jasa' => $jasaItem?->jasa ? [
                'id' => $jasaItem->jasa->id,
                'title' => $jasaItem->jasa->title,
                'image' => $jasaItem->jasa->image,
            ] : null,
            // Review - from jasa_order_items as primary
            'review' => $review ? array_merge($review->toArray(), [
                'media' => $review->media->map(function ($media) {
                    $arr = $media->toArray();
                    // Use media_url (full URL) or construct from file_path
                    $arr['file_url'] = $media->media_url ?? ($media->file_path ? asset('storage/' . $media->file_path) : null);
                    return $arr;
                })->toArray()
            ]) : null,
            'is_reviewed' => $isReviewed,
            // Completion evidences - from jasa_order_items as primary
            'completion_evidences' => $completionEvidences->map(function ($evidence) {
                $arr = $evidence->toArray();
                // Use media_url (full URL) or construct from file_path
                $arr['file_url'] = $evidence->media_url ?? ($evidence->file_path ? asset('storage/' . $evidence->file_path) : null);
                return $arr;
            })->toArray(),
        ];
    }

    /**
     * Mapping from service_orders.status to orders.status
     * orders.status ENUM: 'pending', 'proses', 'selesai', 'batal'
     * service_orders.status ENUM: 'menunggu_konfirmasi_merchant', 'diterima', 'ditolak', 'layanan_dikerjakan', 'menunggu_konfirmasi_selesai', 'selesai'
     */
    private function mapServiceOrderStatusToOrdersStatus(string $serviceOrderStatus): string
    {
        $mapping = [
            'menunggu_konfirmasi_merchant' => 'pending',
            'diterima' => 'proses',           // Diterima = masuk proses
            'ditolak' => 'batal',             // Ditolak = dibatalkan
            'layanan_dikerjakan' => 'proses', // Sedang dikerjakan = masih proses
            'menunggu_konfirmasi_selesai' => 'proses', // Menunggu konfirmasi selesai = proses
            'selesai' => 'selesai',           // Selesai = selesai
        ];

        return $mapping[$serviceOrderStatus] ?? 'pending';
    }

    /**
     * Update service order status (merchant actions)
     *
     * PRIMARY ID: orders.id
     * Fallback: service_orders.id (legacy)
     *
     * Struktur:
     * - orders.id = ID utama pesanan (semua jenis order)
     * - orders -> jasa_order_items = detail item jasa
     * - orders -> service_orders = backward compatibility
     *
     * Flow:
     * 1. Receive {id} from route as orders.id
     * 2. Find Order with relations (jasa_order_items, service_orders)
     * 3. Update orders.status and service_orders.status
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
            'accepted' => ServiceOrder::STATUS_DITERIMA,
            'diterima' => ServiceOrder::STATUS_DITERIMA,
            'rejected' => ServiceOrder::STATUS_DITOLAK,
            'ditolak' => ServiceOrder::STATUS_DITOLAK,
            'in_progress' => ServiceOrder::STATUS_DIKERJAKAN,
            'dikerjakan' => ServiceOrder::STATUS_DIKERJAKAN,
            'completed' => ServiceOrder::STATUS_SELESAI,
            'selesai' => ServiceOrder::STATUS_SELESAI,
            'menunggu_konfirmasi_selesai' => ServiceOrder::STATUS_MENUNGGU_SELESAI,
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
                    ServiceOrder::STATUS_DITERIMA,
                    ServiceOrder::STATUS_DITOLAK,
                    ServiceOrder::STATUS_DIKERJAKAN,
                    ServiceOrder::STATUS_MENUNGGU_SELESAI,
                    ServiceOrder::STATUS_SELESAI,
                ]),
            ],
            'rejection_reason' => 'required_if:status,ditolak|nullable|string|max:500',
            'completion_note' => 'nullable|string|max:1000',
            'evidences' => 'nullable|array|max:5',
            'evidences.*' => 'file|max:51200|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm',
        ]);

        $newStatus = $data['status'];

        // ============================================================
        // FIND DATA - Primary: orders.id
        // ============================================================

        // Try to find as orders.id first (PRIMARY)
        // NOTE: order_type BUKAN 'jasa' - itu mekanisme pemesanan
        $order = Order::with(['jasaItems.serviceOrder'])
            ->where('merchant_id', $merchant->id)
            ->whereHas('jasaItems') // Orders yang punya jasa_order_items
            ->find($id);

        $serviceOrder = null;
        $jasaOrderItem = null;
        $foundVia = null;

        if ($order) {
            $foundVia = 'orders';
            Log::info('[ServiceOrder UpdateStatus] Found via orders table', [
                'received_id' => $id,
                'orders_id' => $order->id,
                'order_status' => $order->status,
            ]);

            // Get related jasa_order_items and service_order
            $jasaOrderItem = $order->jasaItems->first();
            if ($jasaOrderItem && $jasaOrderItem->service_order_id) {
                $serviceOrder = ServiceOrder::find($jasaOrderItem->service_order_id);
                Log::info('[ServiceOrder UpdateStatus] Found related service_order', [
                    'service_orders_id' => $serviceOrder->id,
                    'service_order_status' => $serviceOrder->status,
                ]);
            }
        }

        // Final fallback: try service_orders.id (legacy)
        if (!$order) {
            $serviceOrder = ServiceOrder::with('jasaOrderItems')
                ->where('merchant_id', $merchant->id)
                ->find($id);

            if ($serviceOrder) {
                $foundVia = 'service_orders';
                Log::info('[ServiceOrder UpdateStatus] Found via service_orders table (legacy)', [
                    'received_id' => $id,
                    'service_orders_id' => $serviceOrder->id,
                    'service_order_status' => $serviceOrder->status,
                ]);

                // Try to find related orders via jasa_order_items
                $jasaOrderItem = $serviceOrder->jasaOrderItems->first();
                if ($jasaOrderItem && $jasaOrderItem->order_id) {
                    $order = Order::find($jasaOrderItem->order_id);
                    Log::info('[ServiceOrder UpdateStatus] Found related orders', [
                        'orders_id' => $order->id,
                    ]);
                }
            }
        }

        // If still not found, return 404
        if (!$order && !$serviceOrder) {
            Log::warning('[ServiceOrder UpdateStatus] Order not found', [
                'received_id' => $id,
                'merchant_id' => $merchant->id,
            ]);
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Check if order is in terminal state
        $currentStatus = $serviceOrder?->status ?? $order?->status;
        $terminalStatuses = [ServiceOrder::STATUS_SELESAI, ServiceOrder::STATUS_DITOLAK];
        if (in_array($currentStatus, $terminalStatuses)) {
            $statusLabel = ServiceOrder::getStatusLabelStatic($currentStatus);
            Log::warning('[ServiceOrder UpdateStatus] Order in terminal state', [
                'received_id' => $id,
                'current_status' => $currentStatus,
                'requested_status' => $newStatus,
            ]);
            return ApiResponse::error(
                "Pesanan sudah dalam status akhir ({$statusLabel}) dan tidak dapat diubah lagi.",
                400
            );
        }

        // Validate status transition using service_order if available
        if ($serviceOrder && !$serviceOrder->canTransitionTo($newStatus)) {
            $currentLabel = ServiceOrder::getStatusLabelStatic($serviceOrder->status);
            $newLabel = ServiceOrder::getStatusLabelStatic($newStatus);
            Log::warning('[ServiceOrder UpdateStatus] Invalid transition', [
                'received_id' => $id,
                'current_status' => $serviceOrder->status,
                'requested_status' => $newStatus,
            ]);
            return ApiResponse::error(
                "Tidak dapat mengubah status dari '{$currentLabel}' ke '{$newLabel}'",
                422
            );
        }

        try {
            DB::beginTransaction();

            // ============================================================
            // UPDATE STATUS
            // ============================================================

            // Handle DITOLAK (rejection)
            if ($newStatus === ServiceOrder::STATUS_DITOLAK) {
                $rejectionReason = $data['rejection_reason'] ?? 'Merchant menolak pesanan';

                if ($serviceOrder) {
                    $serviceOrder->updateStatus($newStatus, [
                        'rejection_reason' => $rejectionReason,
                    ]);
                }

                if ($order) {
                    $order->update(['status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus)]);
                }

                Log::info('[ServiceOrder UpdateStatus] Order rejected', [
                    'received_id' => $id,
                    'orders_id' => $order?->id,
                    'service_orders_id' => $serviceOrder?->id,
                    'new_service_status' => $newStatus,
                    'new_order_status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus),
                    'rejection_reason' => $rejectionReason,
                ]);
            }
            // Handle MENUNGGU_SELESAI (upload evidence)
            elseif ($newStatus === ServiceOrder::STATUS_MENUNGGU_SELESAI) {
                $files = $request->file('evidences', []);
                $uploadedCount = 0;

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

                    if ($serviceOrder) {
                        ServiceCompletionEvidence::create([
                            'service_order_id' => $serviceOrder->id,
                            'file_name' => $file->getClientOriginalName(),
                            'file_path' => $path,
                            'file_url' => Storage::url($path),
                            'file_type' => $type,
                            'mime_type' => $file->getMimeType(),
                            'file_size' => $file->getSize(),
                            'display_order' => $index,
                        ]);
                    }

                    $uploadedCount++;
                }

                if (!empty($data['completion_note'])) {
                    if ($serviceOrder) {
                        $serviceOrder->update(['completion_note' => $data['completion_note']]);
                    }
                }

                if ($serviceOrder) {
                    $serviceOrder->updateStatus($newStatus);
                }
                if ($order) {
                    $order->update(['status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus)]);
                }

                Log::info('[ServiceOrder UpdateStatus] Evidence uploaded, status updated', [
                    'received_id' => $id,
                    'orders_id' => $order?->id,
                    'service_orders_id' => $serviceOrder?->id,
                    'new_service_status' => $newStatus,
                    'new_order_status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus),
                    'files_uploaded' => $uploadedCount,
                ]);
            }
            // Handle SELESAI (completion)
            elseif ($newStatus === ServiceOrder::STATUS_SELESAI) {
                $files = $request->file('evidences', []);

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

                    if ($serviceOrder) {
                        ServiceCompletionEvidence::create([
                            'service_order_id' => $serviceOrder->id,
                            'file_name' => $file->getClientOriginalName(),
                            'file_path' => $path,
                            'file_url' => Storage::url($path),
                            'file_type' => $type,
                            'mime_type' => $file->getMimeType(),
                            'file_size' => $file->getSize(),
                            'display_order' => $index,
                        ]);
                    }
                }

                if (!empty($data['completion_note'])) {
                    if ($serviceOrder) {
                        $serviceOrder->update(['completion_note' => $data['completion_note']]);
                    }
                }

                if ($serviceOrder) {
                    $serviceOrder->updateStatus($newStatus);
                }
                if ($order) {
                    $order->update(['status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus)]);
                }

                Log::info('[ServiceOrder UpdateStatus] Order completed', [
                    'received_id' => $id,
                    'orders_id' => $order?->id,
                    'service_orders_id' => $serviceOrder?->id,
                    'new_service_status' => $newStatus,
                    'new_order_status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus),
                ]);
            }
            // Handle DITERIMA or DIKERJAKAN (regular transitions)
            else {
                if ($serviceOrder) {
                    $serviceOrder->updateStatus($newStatus);
                }
                if ($order) {
                    $order->update(['status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus)]);
                }

                Log::info('[ServiceOrder UpdateStatus] Status updated', [
                    'received_id' => $id,
                    'orders_id' => $order?->id,
                    'service_orders_id' => $serviceOrder?->id,
                    'new_service_status' => $newStatus,
                    'new_order_status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus),
                ]);
            }

            DB::commit();

            // Reload data for response
            $responseData = null;
            if ($serviceOrder) {
                $serviceOrder->load(['completionEvidences', 'customer']);
                $responseData = $serviceOrder;
            } elseif ($order) {
                $order->load(['jasaItems.completionEvidences', 'user']);
                $responseData = $order;
            }

            Log::info('[ServiceOrder UpdateStatus] Success', [
                'received_id' => $id,
                'orders_id' => $order?->id,
                'service_orders_id' => $serviceOrder?->id,
                'new_service_status' => $newStatus,
                'new_order_status' => $this->mapServiceOrderStatusToOrdersStatus($newStatus),
                'response_type' => $responseData ? get_class($responseData) : null,
            ]);

            return ApiResponse::success($responseData, 'Status berhasil diperbarui');
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            Log::error('[ServiceOrder UpdateStatus] Validation error', [
                'received_id' => $id,
                'orders_id' => $order?->id,
                'service_orders_id' => $serviceOrder?->id,
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            // Log the FULL error for debugging
            Log::error('[ServiceOrder UpdateStatus] Exception', [
                'received_id' => $id,
                'orders_id' => $order?->id,
                'service_orders_id' => $serviceOrder?->id,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return the ACTUAL error message for debugging
            return ApiResponse::error('Gagal memperbarui status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit service order review (customer action)
     *
     * Refactored: Sync review between orders and service_orders tables
     */
    public function submitReview(Request $request, int $id)
    {
        // Validate only required fields first — media is handled separately by $request->file()
        // is_anonymous sent as string '0'/'1' from FormData, not boolean
        // title is optional (nullable); comment is required; rating is required
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:80',
            'comment' => 'required|string|min:10|max:500',
            'is_anonymous' => 'nullable',
            // media: nullable, single file or array; normalized in controller
            'media' => 'nullable',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:10240',
        ]);

        // Try to find order in orders table first
        // NOTE: order_type BUKAN 'jasa' - itu mekanisme pemesanan
        $order = Order::with(['merchant', 'jasaItems.jasa'])
            ->where('user_id', Auth::id())
            ->whereHas('jasaItems') // Orders yang punya jasa_order_items
            ->find($id);

        $serviceOrder = null;
        $jasaId = null;
        $merchantId = null;
        $jasaItem = null;

        if ($order) {
            $jasaItem = $order->jasaItems->first();
            // jasa_id ada di jasa_order_items, BUKAN di orders
            $jasaId = $jasaItem?->jasa_id;
            $merchantId = $order->merchant_id;
            if ($jasaItem && $jasaItem->service_order_id) {
                $serviceOrder = ServiceOrder::find($jasaItem->service_order_id);
            }
        } else {
            // Fallback: find directly in service_orders
            $serviceOrder = ServiceOrder::with(['merchant'])->findOrFail($id);

            // Verify customer owns this order
            if ($serviceOrder->customer_id !== Auth::id()) {
                return ApiResponse::error('Tidak memiliki akses ke pesanan ini', 403);
            }

            $jasaId = $serviceOrder->jasa_id;
            $merchantId = $serviceOrder->merchant_id;
        }

        // VALIDATION: Order must be completed
        $currentStatus = $serviceOrder?->status ?? $order?->status;
        if ($currentStatus !== ServiceOrder::STATUS_SELESAI) {
            return ApiResponse::error(
                'Pesanan harus selesai terlebih dahulu sebelum memberikan review',
                400
            );
        }

        // VALIDATION: Not already reviewed
        $existingReview = Rating::where('service_order_id', $serviceOrder?->id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$existingReview && $jasaItem) {
            $existingReview = Rating::where('jasa_order_item_id', $jasaItem->id)
                ->where('user_id', Auth::id())
                ->first();
        }

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

            // Determine the jasa for the review
            // NOTE: Order doesn't have 'jasa' relation - use jasaItems.jasa instead
            $jasa = $jasaItem?->jasa ?? $serviceOrder?->jasa ?? Jasa::find($jasaId);

            // Create rating for the service (jasa) with order reference
            $ratingData = [
                'user_id' => Auth::id(),
                'merchant_id' => $merchantId,
                'service_order_id' => $serviceOrder?->id,
                'rateable_id' => $jasaId,
                'rateable_type' => Jasa::class,
                'rating' => (int) $data['rating'],
                'title' => $data['title'] ?? null,
                'comment' => $data['comment'] ?? null,
                'is_anonymous' => $isAnonymous,
            ];

            // Add jasa_order_item_id if available
            if ($jasaItem) {
                $ratingData['jasa_order_item_id'] = $jasaItem->id;
            }

            Log::info('[submitReview] Creating rating with data:', $ratingData);

            $review = Rating::create($ratingData);

            Log::info('[submitReview] Rating created:', ['id' => $review->id]);

            // Handle media uploads ONLY if files are present
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

            // Mark order as reviewed in service_orders
            if ($serviceOrder) {
                $serviceOrder->markAsReviewed($review->id);
            }

            // Also mark as reviewed in jasa_order_items
            if ($order) {
                $jasaItem = $order->jasaItems->first();
                if ($jasaItem) {
                    $jasaItem->update([
                        'is_reviewed' => true,
                        'review_id' => $review->id,
                    ]);
                }
            }

            // Update rating summaries
            $this->updateRatingSummary($jasaId, $merchantId);

            DB::commit();

            // Load relationships for response
            $review->load(['user', 'media']);

            Log::info('[submitReview] Success:', ['review_id' => $review->id]);

            return ApiResponse::success([
                'review' => $review,
                'order' => $serviceOrder ?? $order,
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
