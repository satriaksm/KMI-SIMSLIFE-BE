<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\JasaOrderItem;
use App\Models\Jasa;
use App\Models\Payment;
use App\Models\PaymentFee;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\ServiceConsultation;
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
 * Flow jasa aman:
 * 1. Customer checkout jasa
 * 2. Order dibuat sebagai menunggu_konfirmasi
 * 3. Merchant setuju / tolak terlebih dahulu
 * 4. Jika merchant setuju, customer baru boleh membayar
 * 5. Setelah pembayaran berhasil, layanan baru dapat diproses
 */
class JasaOrderController extends Controller
{
    public function __construct(
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly WebPushService $webPushService
    ) {}

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
            'subtotal' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
            'payment_channel' => 'nullable|string|max:50',
            'voucher_id' => 'nullable|integer|exists:vouchers,id',
            'voucher_code' => 'nullable|string|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'consultation_id' => 'nullable|integer|exists:service_consultations,id',
        ]);

        $jasa = Jasa::with(['merchant', 'images'])->findOrFail($request->jasa_id);

        $consultation = null;
        $isConsultationOrder = false;

        if ($request->filled('consultation_id')) {
            $consultation = ServiceConsultation::with(['jasa', 'merchant', 'customer', 'jasaOrderItems.order.payment'])
                ->where('id', $request->consultation_id)
                ->where('customer_id', Auth::id())
                ->where('jasa_id', $jasa->id)
                ->first();

            if (!$consultation) {
                return ApiResponse::error('Konsultasi tidak ditemukan atau tidak sesuai dengan layanan ini.', 404);
            }

            if (!in_array($consultation->status, [
                ServiceConsultation::STATUS_DAPAT_DIKERJAKAN,
                ServiceConsultation::STATUS_PENYESUAIAN,
                ServiceConsultation::STATUS_ACCEPTED,
            ], true)) {
                return ApiResponse::error('Konsultasi belum memiliki penawaran aktif dari merchant.', 422);
            }

            if (empty($consultation->merchant_offered_price) && empty($consultation->negotiated_price)) {
                return ApiResponse::error('Harga penawaran konsultasi belum tersedia.', 422);
            }

            $existingConsultationItem = JasaOrderItem::with('order.payment')
                ->where('service_consultation_id', $consultation->id)
                ->whereHas('order', function ($query) {
                    $query->whereNotIn('status', ['expired', 'dibatalkan', 'ditolak']);
                })
                ->latest('id')
                ->first();

            if ($existingConsultationItem?->order) {
                $existingOrder = $existingConsultationItem->order;

                if (strtoupper((string) $existingOrder->payment_status) !== 'PAID') {
                    $deadline = $existingOrder->payment?->expired_at ?? $existingOrder->confirm_deadline;

                    if ($deadline && now()->greaterThan($deadline)) {
                        DB::transaction(function () use ($existingOrder, $consultation) {
                            $existingOrder->update([
                                'status' => 'expired',
                                'expired_at' => now(),
                            ]);

                            if ($existingOrder->payment && $existingOrder->payment->status === 'pending') {
                                $existingOrder->payment->update(['status' => 'expired']);
                            }

                            $consultation->update([
                                'status' => ServiceConsultation::STATUS_CLOSED,
                                'closed_at' => now(),
                                'offer_status' => 'payment_expired',
                            ]);
                        });

                        return ApiResponse::error('Batas waktu pembayaran sudah habis. Percakapan otomatis dihentikan.', 422);
                    }
                }

                return ApiResponse::success([
                    'order_id' => $existingOrder->id,
                    'jasa_order_item_id' => $existingConsultationItem->id,
                    'payment_method' => $existingOrder->payment_method_snapshot ?? $existingOrder->payment_method,
                    'payment_channel' => $existingOrder->payment_channel_snapshot ?? $existingOrder->payment_channel,
                    'is_cod' => strtoupper((string) ($existingOrder->payment_method ?? '')) === 'COD',
                    'status' => $existingOrder->status,
                    'payment_status' => $existingOrder->payment_status,
                    'confirm_deadline' => $existingOrder->confirm_deadline?->toISOString(),
                    'already_exists' => true,
                    'subtotal' => (float) ($existingOrder->subtotal_snapshot ?? $existingOrder->subtotal ?? $existingOrder->total_price),
                    'discount_total' => 0,
                    'payment_fee' => (float) ($existingOrder->payment_fee_snapshot ?? $existingOrder->platform_fee_snapshot ?? 0),
                    'total_payment' => (float) ($existingOrder->total_payment_snapshot ?? $existingOrder->total_price),
                ], 'Pesanan konsultasi sudah dibuat. Silakan lanjutkan pembayaran.', 200);
            }

            $isConsultationOrder = true;
        }

        // Validasi: hanya untuk UMKM Jasa
        if (!$jasa->merchant || $jasa->merchant->segmentation_id !== 3) {
            return ApiResponse::error('Layanan ini tidak tersedia untuk dipesan', 400);
        }

        // Validasi: langsung_pesan/booking untuk order biasa, memerlukan_konsultasi untuk order dari chat konsultasi.
        $caraPemesanan = $jasa->cara_pemesanan ?? 'langsung_pesan';
        if (!$isConsultationOrder && !in_array($caraPemesanan, ['langsung_pesan', 'booking'], true)) {
            return ApiResponse::error(
                'Layanan ini memerlukan konsultasi terlebih dahulu. Silakan gunakan fitur Ajukan Konsultasi.',
                400
            );
        }

        if ($isConsultationOrder && $caraPemesanan !== 'memerlukan_konsultasi') {
            return ApiResponse::error('Order konsultasi hanya dapat dibuat dari layanan yang memerlukan konsultasi.', 422);
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

        if ($isConsultationOrder) {
            $orderMethod = 'konsultasi';
        }

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

        if ($isConsultationOrder) {
            $orderMethod = 'konsultasi';
        }

        $paymentMethod = strtoupper($request->payment_method ?? 'COD');
        $paymentChannel = $request->payment_channel
            ? strtoupper($request->payment_channel)
            : null;
        $isCodPayment = strtolower($paymentMethod) === 'cod';

        // subtotal: harga layanan (sebelum fee)
        $subtotal = floatval($request->subtotal ?? $request->total_price ?? 0);
        if ($isConsultationOrder) {
            $subtotal = floatval($consultation->negotiated_price ?? $consultation->merchant_offered_price ?? 0);
        }
        if ($subtotal <= 0) {
            $subtotal = floatval($jasa->fixed_price ?? $jasa->base_price ?? $jasa->price ?? 0);
        }

        // ============================================================
        // VOUCHER / DISKON
        // Frontend boleh mengirim voucher_id / voucher_code / discount_amount,
        // tetapi backend tetap menghitung ulang dari data voucher agar aman.
        // Rumus total jasa:
        // subtotal - discount_total + payment_fee = total_payment
        // ============================================================
        $discountTotal = 0;
        $appliedVoucher = null;
        $voucherCode = $request->filled('voucher_code')
            ? strtoupper(trim((string) $request->voucher_code))
            : null;

        if (!$isConsultationOrder && ($request->filled('voucher_id') || $voucherCode)) {
            $acceptedEventIds = DB::table('event_merchants')
                ->select('event_id')
                ->where('merchant_id', $merchantId)
                ->where('status', 'accepted')
                ->pluck('event_id');

            $voucherQuery = Voucher::query()
                ->where(function ($query) use ($merchantId, $acceptedEventIds) {
                    $query->where('merchant_id', $merchantId)
                        ->orWhereIn('event_id', $acceptedEventIds);
                })
                ->where('is_secret', false)
                ->active();

            if ($request->filled('voucher_id')) {
                $voucherQuery->where('id', $request->voucher_id);
            } else {
                $voucherQuery->whereRaw('UPPER(voucher_code) = ?', [$voucherCode]);
            }

            $appliedVoucher = $voucherQuery->first();

            if (!$appliedVoucher) {
                return ApiResponse::error('Voucher tidak valid atau sudah tidak aktif.', 422);
            }

            $minPurchase = (float) ($appliedVoucher->min_purchase_amount ?? 0);
            if ($minPurchase > 0 && $subtotal < $minPurchase) {
                return ApiResponse::error(
                    'Minimal transaksi untuk voucher ini adalah Rp ' . number_format($minPurchase, 0, ',', '.'),
                    422
                );
            }

            $usageLimit = (int) ($appliedVoucher->usage_limit ?? 0);
            $usageLimitPerUser = (int) ($appliedVoucher->usage_limit_per_user ?? 0);

            if ($usageLimit > 0) {
                $totalUsed = $appliedVoucher->usages()->count();

                if ($totalUsed >= $usageLimit) {
                    return ApiResponse::error('Kuota voucher sudah habis.', 422);
                }
            }

            if ($customerId && $usageLimitPerUser > 0) {
                $userUsed = $appliedVoucher->usages()
                    ->where('user_id', $customerId)
                    ->count();

                if ($userUsed >= $usageLimitPerUser) {
                    return ApiResponse::error('Voucher sudah mencapai batas pemakaian untuk akun Anda.', 422);
                }
            }

            $voucherType = strtolower((string) $appliedVoucher->voucher_type);
            $voucherValue = (float) ($appliedVoucher->value ?? 0);
            $maxDiscount = (float) ($appliedVoucher->max_discount_amount ?? 0);

            if (in_array($voucherType, ['percent', 'percentage', 'persen'], true)) {
                $discountTotal = round(($voucherValue / 100) * $subtotal);

                if ($maxDiscount > 0) {
                    $discountTotal = min($discountTotal, $maxDiscount);
                }
            } else {
                $discountTotal = $voucherValue;
            }

            $discountTotal = max(0, min($discountTotal, $subtotal));
        }

        $payableSubtotal = max(0, $subtotal - $discountTotal);

        // platform_fee: biaya admin/platform yang dibebankan ke customer.
        // Biaya admin dihitung dari nominal setelah diskon.
        $platformFee = 0;
        if (!$isCodPayment && $paymentChannel) {
            $platformFee = PaymentFee::calculatePlatformFee($paymentChannel, $payableSubtotal);
        }

        // total_price yang customer bayarkan = subtotal setelah diskon + platform_fee
        $totalPrice = $payableSubtotal + $platformFee;

        // Determine service location address
        $serviceLocationAddress = null;
        if ($serviceType === 'online') {
            $serviceLocationAddress = 'Online';
        } elseif ($serviceType === 'di_tempat_umkm' || $serviceType === 'at_location') {
            $serviceLocationAddress = $jasa->location_address ?? $jasa->merchant?->address ?? null;
        }

        $confirmMinutes = (int) config('app.order_confirm_minutes', 60);

        DB::beginTransaction();
        try {
            // Tentukan initial status berdasarkan flow.
            // Order konsultasi sudah memiliki penawaran merchant, jadi langsung masuk tahap pembayaran.
            $initialStatus = $isConsultationOrder ? 'diterima' : 'menunggu_konfirmasi';

            // Create order
            $customerName = $request->customer_name
                ?? Auth::user()?->name
                ?? Auth::user()?->nama
                ?? 'Customer';

            $customerPhone = (string) ($request->customer_phone ?? '');
            $customerAddress = (string) ($request->customer_address ?? $serviceLocationAddress ?? '');
            $orderCode = 'JS-' . now()->format('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

            $order = Order::create([
                'user_id' => $customerId,
                'merchant_id' => $merchantId,
                'order_type' => 'jasa',
                'order_code' => $orderCode,

                'total_price' => $totalPrice,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'delivery_type' => 'pickup',
                'delivery_fee_snapshot' => 0,
                'platform_fee' => $platformFee,
                'gross_amount' => $totalPrice,
                'net_amount' => $payableSubtotal,

                'payment_method' => $paymentMethod,
                'payment_status' => 'UNPAID',
                'status' => $initialStatus,

                'notes' => $request->booking_note,

                // SLA: order konsultasi tidak perlu konfirmasi merchant lagi.
                'merchant_response_deadline' => $isConsultationOrder ? null : now()->addHours((int) config('sla.merchant_response_hours', 24)),
                'merchant_responded_at' => $isConsultationOrder ? now() : null,
                'confirm_deadline' => $isConsultationOrder ? now()->addHour() : null,

                // Snapshot customer versi product/order umum
                'user_name_snapshot' => $customerName,
                'user_phone_snapshot' => $customerPhone,
                'address_detail_snapshot' => $customerAddress,
                'province_name_snapshot' => '',
                'city_name_snapshot' => '',
                'district_name_snapshot' => '',
                'village_name_snapshot' => '',
                'latitude_snapshot' => $request->latitude,
                'longitude_snapshot' => $request->longitude,

                // Snapshot customer versi jasa
                'customer_name_snapshot' => $customerName,
                'customer_phone_snapshot' => $customerPhone,
                'customer_address_snapshot' => $customerAddress,

                // Snapshot merchant
                'merchant_name_snapshot' => $jasa->merchant->name,
                'merchant_phone_snapshot' => $jasa->merchant->phone ?? '',
                'merchant_address_snapshot' => $jasa->merchant->address ?? '',

                // Snapshot payment
                'payment_method_snapshot' => $paymentMethod,
                'payment_channel_snapshot' => $paymentChannel,
                'subtotal_snapshot' => $subtotal,
                'admin_fee_snapshot' => $platformFee,
                'platform_fee_snapshot' => $platformFee,
                'payment_fee_snapshot' => $platformFee,
                'total_payment_snapshot' => $totalPrice,
            ]);

            // Catat pemakaian voucher segera saat order berhasil dibuat.
            // Voucher dianggap terpakai sejak order masuk menunggu konfirmasi merchant.
            // Jika order dibatalkan/ditolak/expired, usage ini akan dilepas kembali.
            if ($appliedVoucher && $discountTotal > 0 && $customerId) {
                VoucherUsage::create([
                    'user_id' => $customerId,
                    'voucher_id' => $appliedVoucher->id,
                    'order_id' => $order->id,
                    'discount_amount' => $discountTotal,
                ]);
            }

            if ($isConsultationOrder && $consultation) {
                $consultation->update([
                    'negotiated_price' => $subtotal,
                    'offer_status' => 'waiting_payment',
                ]);
            }

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
                'service_consultation_id' => $consultation?->id,
                'quantity' => 1,
                'price' => $payableSubtotal,
                'subtotal' => $payableSubtotal,
                'booking_date' => $isConsultationOrder ? null : $request->booking_date,
                'booking_time' => $isConsultationOrder ? null : $request->booking_time,
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
                'jasa_price_snapshot' => $subtotal,
                'original_price_snapshot' => $jasa->base_price ?? $jasa->price ?? 0,
                'offered_price_snapshot' => $payableSubtotal,
                'agreed_price_snapshot' => $payableSubtotal,
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
                'discount_total' => $discountTotal,
                'voucher_code' => $appliedVoucher?->voucher_code,
            ]);

            // Build response
            $responseData = [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'payment_method' => $paymentMethod,
                'payment_channel' => $paymentChannel,
                'is_cod' => $isCodPayment,
                'status' => $order->status,
                'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
                // Fee breakdown
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'payment_fee' => $platformFee,
                'total_payment' => $totalPrice,
                'voucher_code' => $appliedVoucher?->voucher_code,
                'consultation_id' => $consultation?->id,
                'is_consultation_order' => $isConsultationOrder,
            ];

            // COD: redirect URL
            if ($isCodPayment) {
                $redirectBase = config('app.url') . '/booking-confirmation';
                $responseData['redirect_url'] = "{$redirectBase}?order_id={$order->id}&status=pending";
            }

            return ApiResponse::success(
                $responseData,
                'Pesanan berhasil dibuat. Menunggu konfirmasi merchant.'
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
            'jasaItems',
            'jasaItems.jasa:id,title,image,service_type',
            'jasaItems.jasa.categories',
            'jasaItems.review',
            'jasaItems.completionEvidences',
            'payment',
        ])
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $orders->map(function ($order) use ($customerId) {
            // Auto-expire if deadline passed (source of truth: orders table)
            $this->autoExpireOrder($order);

            $jasaItem = $order->jasaItems->first();
            $payment = $order->payment;

            $review = null;
            if ($jasaItem) {
                // Primary check: order_id + jasa_order_item_id + user_id
                $review = Rating::where('order_id', $order->id)
                    ->where('jasa_order_item_id', $jasaItem->id)
                    ->where('user_id', $customerId)
                    ->first();

                // Fallback check: jasa_order_item_id + user_id
                if (!$review) {
                    $review = Rating::where('jasa_order_item_id', $jasaItem->id)
                        ->where('user_id', $customerId)
                        ->first();
                }
            }
            $isReviewed = $review !== null;
            $canReview = in_array($order->status, ['completed', 'selesai']) && !$isReviewed;
            $canUpdateReview = $isReviewed && ($review->update_count ?? 0) < 1;

            // Use snapshot data (prioritize snapshot over live data)
            $merchantName = $order->merchant_name_snapshot ?? $order->merchant?->name ?? 'Merchant';
            $merchantLogoUrl = $this->getMerchantLogoUrl($order->merchant);
            $serviceTitle = $jasaItem?->jasa_title_snapshot ?? $jasaItem?->jasa?->title ?? $jasaItem?->jasa?->name ?? 'Layanan';
            $serviceImage = $jasaItem?->jasa_image_snapshot ?? $jasaItem?->jasa?->image_url ?? null;
            $totalPrice = $order->total_payment_snapshot ?? $order->total_price ?? 0;
            $paymentMethod = $order->payment_method_snapshot ?? $order->payment_method ?? 'COD';
            $serviceType = $jasaItem?->service_type_snapshot
                ?? $jasaItem?->service_type
                ?? $jasaItem?->jasa?->service_type
                ?? null;
            $serviceTypeLabel = $this->getServiceTypeLabel($serviceType);
            $orderMethod = $jasaItem?->order_method ?? null;
            $orderMethodLabel = $this->getOrderMethodLabel($orderMethod);
            $categoryName = $jasaItem?->jasa?->categories?->first()?->name ?? null;
            $paymentChannel = $order->payment_channel_snapshot ?? $order->payment_channel ?? null;

            // ─── Fee breakdown ────────────────────────────────────────────────────
            // Use saved snapshots if available; otherwise derive for legacy orders
            $isCod = strtoupper($paymentMethod) === 'COD';
            $savedSubtotal = (float) ($order->subtotal_snapshot ?? 0);
            $savedPlatformFee = (float) ($order->platform_fee_snapshot ?? 0);
            $savedTotalPayment = (float) ($order->total_payment_snapshot ?? 0);
            $savedPaymentFee = (float) ($order->payment_fee_snapshot ?? 0);
            $discountTotal = (float) ($order->discount_total ?? 0);
            $voucherInfo = $this->getOrderVoucherInfo($order);
            $invoice = 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);

            if ($savedSubtotal > 0 && $savedTotalPayment > 0) {
                // New order: use saved snapshots
                $subtotal = $savedSubtotal;
                $platformFee = $isCod ? 0 : $savedPlatformFee;
                $paymentFee = $savedPaymentFee;
                $totalPayment = $savedTotalPayment;
            } elseif ($savedTotalPayment > 0) {
                // Legacy order: subtotal_snapshot is null, but total_payment_snapshot is set
                // Try to derive service price from jasaOrderItem snapshots
                $legacySubtotal = (float) ($jasaItem?->original_price_snapshot ?? 0);
                if ($legacySubtotal <= 0) {
                    // Fallback: jasa_price_snapshot (may include fee for old buggy orders)
                    $legacySubtotal = (float) ($jasaItem?->jasa_price_snapshot ?? $order->total_price ?? 0);
                }
                $subtotal = $legacySubtotal;
                $totalPayment = $savedTotalPayment;
                // Fee = what customer actually paid minus service subtotal
                $paymentFee = max($totalPayment - $subtotal, 0);
                // For non-COD: recalculate expected fee as verification
                $platformFee = $isCod ? 0 : (
                    $savedPlatformFee > 0 ? $savedPlatformFee : $paymentFee
                );
            } else {
                // Fully legacy order: no snapshots at all
                $subtotal = (float) ($jasaItem?->original_price_snapshot ?? $order->total_price ?? 0);
                $totalPayment = (float) ($order->total_price ?? 0);
                $paymentFee = 0;
                $platformFee = 0;
            }

            // Build response - use BOTH id and order_id for FE compatibility
            // id: used by ServiceOrderCard/key binding; order_id: canonical name
            return [
                'id' => $order->id,
                'order_id' => $order->id,
                'order_type' => 'jasa',
                'invoice' => $invoice,
                'order_number' => $invoice,
                'formatted_order_number' => $invoice,
                'nomor_pesanan' => $invoice,
                'order_code' => $invoice,
                'jasa_order_item_id' => $jasaItem?->id,
                'status' => $order->status,
                'order_status' => $order->status,
                'status_label' => $this->getServiceStatusLabel($order->status),
                'payment_status' => $order->payment_status,
                'payment_method' => $paymentMethod,
                // Fee breakdown
                'subtotal' => $subtotal,
                'payment_fee' => $paymentFee,
                'platform_fee' => $platformFee,
                'discount_total' => $discountTotal,
                'discount' => $discountTotal,
                'voucher_usage_id' => $voucherInfo['voucher_usage_id'],
                'voucher_id' => $voucherInfo['voucher_id'],
                'voucher_code' => $voucherInfo['voucher_code'],
                'voucher_name' => $voucherInfo['voucher_name'],
                'voucher_discount_amount' => $voucherInfo['voucher_discount_amount'],
                'is_cod' => $isCod,
                'total_price' => $totalPrice,
                'total_payment' => $totalPayment,
                'payment_channel' => $paymentChannel,
                'merchant' => [
                    'id' => $order->merchant?->id,
                    'name' => $merchantName,
                    'slug' => $order->merchant?->slug,
                    'logo_url' => $merchantLogoUrl,
                    'logoUrl' => $merchantLogoUrl,
                ],
                // Flat merchant name for card convenience
                'merchant_name' => $merchantName,
                // Snapshot service data
                'service_title' => $serviceTitle,
                'service_image' => $serviceImage,
                'service_type' => $serviceType,
                'service_type_label' => $serviceTypeLabel,
                'category_name' => $categoryName,
                'cara_pemesanan' => $orderMethod,
                'cara_pemesanan_label' => $orderMethodLabel,
                // Payment info for "continue payment" button
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'invoice_url' => $payment->invoice_url,
                    'expired_at' => $payment->expired_at?->toISOString(),
                    'subtotal' => $subtotal,
                    'payment_fee' => $paymentFee,
                    'discount_total' => $discountTotal,
                    'voucher_code' => $voucherInfo['voucher_code'],
                    'voucher_name' => $voucherInfo['voucher_name'],
                    'total_payment' => $totalPayment,
                    'payment_method' => $paymentMethod,
                    'payment_channel' => $paymentChannel,
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
                        'image_url' => $ev->file_url,
                        'url' => $ev->file_url,
                        'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                        'note' => $ev->note ?? null,
                        'created_at' => $ev->created_at?->toISOString(),
                    ];
                })?->toArray() ?? [],
                'completion_note' => $jasaItem?->completion_note ?? null,
                'review' => $review ? $review->toArray() : null,
                'is_reviewed' => $isReviewed,
                'can_review' => $canReview,
                'can_update_review' => $canUpdateReview,
                'review_updated_count' => $review ? ($review->update_count ?? 0) : 0,
                'is_review_updated' => $review && ($review->update_count ?? 0) >= 1,
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
            'jasaItems.jasa:id,title,image,description,service_type',
            'jasaItems.jasa.categories',
            'jasaItems.review',
            'jasaItems.completionEvidences',
            'payment',
        ])
            ->where('id', $orderId)
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Auto-expire if deadline passed (source of truth: orders table)
        $this->autoExpireOrder($order);

        $jasaItem = $order->jasaItems->first();

        $review = null;
        if ($jasaItem) {
            // Primary check: order_id + jasa_order_item_id + user_id
            $review = Rating::with(['media'])->where('order_id', $order->id)
                ->where('jasa_order_item_id', $jasaItem->id)
                ->where('user_id', $customerId)
                ->first();

            // Fallback check: jasa_order_item_id + user_id
            if (!$review) {
                $review = Rating::with(['media'])->where('jasa_order_item_id', $jasaItem->id)
                    ->where('user_id', $customerId)
                    ->first();
            }
        }
        $isReviewed = $review !== null;
        $canReview = in_array($order->status, ['completed', 'selesai']) && !$isReviewed;
        $canUpdateReview = $isReviewed && ($review->update_count ?? 0) < 1;

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
        $serviceType = $jasaItem?->service_type_snapshot
            ?? $jasaItem?->service_type
            ?? $jasaItem?->jasa?->service_type
            ?? null;
        $serviceTypeLabel = $this->getServiceTypeLabel($serviceType);
        $orderMethod = $jasaItem?->order_method ?? null;
        $categoryName = $jasaItem?->jasa?->categories?->first()?->name ?? null;
        $merchantAddress = $this->getMerchantFullAddress($order);
        $merchantLogoUrl = $this->getMerchantLogoUrl($order->merchant);
        $serviceLocationAddress = $this->getServiceLocationAddress($serviceType, $order, $jasaItem);
        // Get payment info
        $payment = Payment::where('order_id', $orderId)->first();

        // ─── Fee breakdown ────────────────────────────────────────────────────
        // Use saved snapshots if available; otherwise derive for legacy orders
        $isCod = strtoupper($paymentMethod) === 'COD';
        $savedSubtotal = (float) ($order->subtotal_snapshot ?? 0);
        $savedPlatformFee = (float) ($order->platform_fee_snapshot ?? 0);
        $savedTotalPayment = (float) ($order->total_payment_snapshot ?? 0);
        $savedPaymentFee = (float) ($order->payment_fee_snapshot ?? 0);
        $discountTotal = (float) ($order->discount_total ?? 0);
        $voucherInfo = $this->getOrderVoucherInfo($order);

        if ($savedSubtotal > 0 && $savedTotalPayment > 0) {
            // New order: use saved snapshots
            $subtotal = $savedSubtotal;
            $platformFee = $isCod ? 0 : $savedPlatformFee;
            $paymentFee = $savedPaymentFee;
            $totalPayment = $savedTotalPayment;
        } elseif ($savedTotalPayment > 0) {
            // Legacy order: subtotal_snapshot is null, but total_payment_snapshot is set
            // Try to derive service price from jasaOrderItem snapshots
            $legacySubtotal = (float) ($jasaItem?->original_price_snapshot ?? 0);
            if ($legacySubtotal <= 0) {
                $legacySubtotal = (float) ($jasaItem?->jasa_price_snapshot ?? $order->total_price ?? 0);
            }
            $subtotal = $legacySubtotal;
            $totalPayment = $savedTotalPayment;
            $paymentFee = max($totalPayment - $subtotal, 0);
            $platformFee = $isCod ? 0 : (
                $savedPlatformFee > 0 ? $savedPlatformFee : $paymentFee
            );
        } else {
            // Fully legacy order: no snapshots at all
            $subtotal = (float) ($jasaItem?->original_price_snapshot ?? $order->total_price ?? 0);
            $totalPayment = (float) ($order->total_price ?? 0);
            $paymentFee = 0;
            $platformFee = 0;
        }
        // Kept for response compatibility
        $totalPrice = $totalPayment;

        // Transform completion evidences from jasa_order_items relation
        $completionEvidences = $order->jasaItems->flatMap(function ($item) {
            return $item->completionEvidences;
        })->map(function ($ev) {
            return [
                'id' => $ev->id,
                'jasa_order_item_id' => $ev->jasa_order_item_id,
                'file_path' => $ev->file_path,
                'file_url' => $ev->file_url,
                'image_url' => $ev->file_url,
                'url' => $ev->file_url,
                'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                'note' => $ev->note ?? null,
                'created_at' => $ev->created_at?->toISOString(),
            ];
        });

        return ApiResponse::success([
            'id' => $order->id,
            'order_id' => $order->id,
            'order_type' => 'jasa',
            'invoice' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'order_number' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'nomor_pesanan' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'order_code' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
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
            // Fee breakdown
            'subtotal' => $subtotal,
            'payment_fee' => $paymentFee,
            'platform_fee' => $platformFee,
            'admin_fee' => $platformFee,
            'discount_total' => $discountTotal,
            'discount' => $discountTotal,
            'voucher_usage_id' => $voucherInfo['voucher_usage_id'],
            'voucher_id' => $voucherInfo['voucher_id'],
            'voucher_code' => $voucherInfo['voucher_code'],
            'voucher_name' => $voucherInfo['voucher_name'],
            'voucher_discount_amount' => $voucherInfo['voucher_discount_amount'],
            'is_cod' => $isCod,
            'total_price' => $totalPrice,
            'total_payment' => $totalPayment,
            'total' => $totalPayment,
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
            'customer' => [
                'id' => $order->user_id,
                'name' => $customerName,
                'phone' => $customerPhone,
                'email' => $order->user?->email ?? '',
                'profile_picture' => $order->user?->profile_picture ?? null,
            ],
            // Service info (flat + inside jasa_order_item)
            'service_name' => $serviceTitle,
            'category_name' => $categoryName,
            'service_type' => $serviceType,
            'service_type_label' => $serviceTypeLabel,
            'tipe_layanan' => $serviceTypeLabel,
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
                'logo_url' => $merchantLogoUrl,
                'logoUrl' => $merchantLogoUrl,
            ],
            'merchant_name' => $merchantName,
            'merchant_phone' => $merchantPhone,
            'merchant_logo_url' => $merchantLogoUrl,
            'service_description' => $serviceDescription,
            'items' => [
                [
                    'id' => $jasaItem?->id,
                    'jasa_order_item_id' => $jasaItem?->id,
                    'name' => $serviceTitle,
                    'service_name' => $serviceTitle,
                    'service_name_snapshot' => $serviceTitle,
                    'image_url' => $serviceImage,
                    'service_image_snapshot' => $serviceImage,
                    'image' => $serviceImage,
                    'price' => $subtotal,
                    'total_price' => $totalPrice,
                    'qty' => 1,
                    'quantity' => 1,
                    'category' => $categoryName,
                    'category_name' => $categoryName,
                    'category_name_snapshot' => $categoryName,
                    'subtotal' => $subtotal,
                ]
            ],
            'amounts' => [
                'subtotal' => $subtotal,
                'discount' => $discountTotal,
                'voucher_code' => $voucherInfo['voucher_code'],
                'voucher_name' => $voucherInfo['voucher_name'],
                'shipping' => 0,
                'platform_fee' => $platformFee,
                'admin_fee' => $platformFee,
                'total' => $totalPayment,
            ],
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
                'subtotal' => $subtotal,
                'payment_fee' => $paymentFee,
                'discount_total' => $discountTotal,
                'voucher_code' => $voucherInfo['voucher_code'],
                'voucher_name' => $voucherInfo['voucher_name'],
                'total_payment' => $totalPayment,
                'payment_method' => $paymentMethod,
                'payment_channel' => $paymentChannel,
            ] : null,
            'completion_evidences' => $completionEvidences->toArray(),
            'completion_note' => $jasaItem?->completion_note ?? null,
            'review' => $review ? $review->toArray() : null,
            'is_reviewed' => $isReviewed,
            'can_review' => $canReview,
            'can_update_review' => $canUpdateReview,
            'review_updated_count' => $review ? ($review->update_count ?? 0) : 0,
            'is_review_updated' => $review && ($review->update_count ?? 0) >= 1,
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

        $paymentMethod = strtoupper((string) ($order->payment_method ?? ''));
        $paymentStatus = strtoupper((string) ($order->payment_status ?? ''));

        /*
     |--------------------------------------------------------------------------
     | Flow cancel jasa baru
     |--------------------------------------------------------------------------
     | Customer boleh batal jika:
     | 1. Pesanan masih menunggu konfirmasi merchant
     | 2. Pesanan sudah diterima merchant, tetapi customer belum bayar
     |
     | Customer tidak boleh batal jika:
     | 1. Pembayaran sudah PAID/SETTLED/SUCCEEDED
     | 2. Layanan sudah diproses
     | 3. Pesanan sudah selesai/ditolak/dibatalkan/expired
     */
        $cancellableStatuses = [
            'pending',
            'menunggu_konfirmasi',
            'menunggu_konfirmasi_merchant',
            'diterima',
        ];

        if (!in_array($order->status, $cancellableStatuses)) {
            return ApiResponse::error(
                "Pesanan dengan status '{$order->status}' tidak dapat dibatalkan.",
                422
            );
        }

        // Kalau sudah bayar non-COD, jangan batalkan langsung karena perlu flow refund.
        if (
            $paymentMethod !== 'COD' &&
            in_array($paymentStatus, ['PAID', 'SETTLED', 'SUCCEEDED', 'LUNAS', 'SUDAH_BAYAR'])
        ) {
            return ApiResponse::error(
                'Pesanan sudah dibayar dan tidak dapat dibatalkan langsung. Silakan hubungi merchant/admin untuk proses lebih lanjut.',
                422
            );
        }

        DB::transaction(function () use ($order, $orderId) {
            // Cancel pending/unpaid payment jika ada
            $payment = Payment::where('order_id', $orderId)
                ->whereIn('status', ['PENDING', 'UNPAID'])
                ->first();

            if ($payment) {
                $payment->update(['status' => 'EXPIRED']);
                event(new \App\Events\PaymentStatusUpdated($payment));
            }

            // Update order status
            $order->update([
                'status' => 'dibatalkan',
                'cancelled_at' => now(),
                'cancelled_by' => 'customer',
            ]);

            $this->releaseVoucherUsage($order);

            // Update jasa_order_items status jika ada
            $jasaItem = $order->jasaItems->first();

            if ($jasaItem) {
                $jasaItem->update([
                    'status' => 'dibatalkan',
                ]);
            }
        });

        Log::info('[JasaOrderController] Order cancelled by customer', [
            'order_id' => $order->id,
            'previous_status' => $order->getOriginal('status'),
            'cancelled_by' => $customerId,
        ]);

        $freshOrder = $order->fresh();

        event(new OrderStatusUpdated($freshOrder, 'dibatalkan'));

        return ApiResponse::success([
            'id' => $freshOrder->id,
            'order_id' => $freshOrder->id,
            'status' => 'dibatalkan',
            'order_status' => 'dibatalkan',
            'status_label' => 'Dibatalkan',
            'cancelled_at' => $freshOrder->cancelled_at?->toISOString(),
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
     * Resolve merchant logo URL for API response.
     */
    private function getMerchantLogoUrl(?\App\Models\Merchant $merchant): ?string
    {
        if (!$merchant) {
            return null;
        }

        $logo = $merchant->logo_path
            ?? $merchant->logo_url
            ?? $merchant->logo
            ?? $merchant->image
            ?? null;

        if (!$logo) {
            return null;
        }

        $logo = str_replace('\\', '/', (string) $logo);

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        if (str_starts_with($logo, '/storage/')) {
            return asset(ltrim($logo, '/'));
        }

        $cleanLogo = ltrim(str_replace(['storage/', 'public/'], '', $logo), '/');

        return asset('storage/' . $cleanLogo);
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
     * Auto-expire order if merchant_response_deadline has passed.
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

        // 1. Pending payment expired (Transfer belum bayar - batas waktu 2 jam)
        if (strtoupper($order->payment_method ?? '') !== 'COD') {
            // Check if related payment has expired
            if ($order->payment && $order->payment->expired_at && now()->greaterThan($order->payment->expired_at)) {
                $order->update([
                    'status' => 'batal',
                    'cancelled_at' => now(),
                ]);
                $this->releaseVoucherUsage($order);
                $order->status = 'batal';
                $order->cancelled_at = now();

                $order->payment->update(['status' => 'EXPIRED']);

                Log::info('[JasaOrderController] Order payment expired, auto-cancelled (2 hours)', [
                    'order_id' => $order->id,
                    'payment_id' => $order->payment->id,
                    'expired_at' => $order->payment->expired_at->toISOString(),
                ]);
                return true;
            }

            // Check if no payment record but order created more than 2 hours ago
            if (!$order->payment && now()->diffInHours($order->created_at) >= 2) {
                $order->update([
                    'status' => 'batal',
                    'cancelled_at' => now(),
                ]);
                $this->releaseVoucherUsage($order);
                $order->status = 'batal';
                $order->cancelled_at = now();

                Log::info('[JasaOrderController] Order has no payment and is > 2 hours old, auto-cancelled', [
                    'order_id' => $order->id,
                    'created_at' => $order->created_at->toISOString(),
                ]);
                return true;
            }
        }

        // 2. Merchant response deadline passed (UMKM tidak konfirmasi)
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

        $this->releaseVoucherUsage($order);

        // Update in-memory model so transformations pick up the new status
        $order->status = 'expired';
        $order->expired_at = now();

        Log::info('[JasaOrderController] Order response deadline passed, auto-expired', [
            'order_id' => $order->id,
            'deadline' => $order->merchant_response_deadline->toISOString(),
        ]);

        return true;
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

        // NOTE: Load jasaItems with snapshot fields and fallbacks.
        $query = Order::with([
            'jasaItems',
            'jasaItems.jasa:id,title,image,service_type',
            'jasaItems.completionEvidences'
        ])
            ->where('merchant_id', $merchant->id)
            ->where('order_type', 'jasa');

        if ($status) {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('created_at')->paginate($perPage);

        $data = $orders->map(function ($order) {
            // Auto-expire if deadline passed (source of truth: orders table)
            $this->autoExpireOrder($order);

            $jasaItem = $order->jasaItems->first();

            $paymentMethod = $order->payment_method_snapshot ?? $order->payment_method ?? 'COD';
            $paymentChannel = $order->payment_channel_snapshot ?? $order->payment_channel;

            $serviceType = $jasaItem?->service_type_snapshot
                ?? $jasaItem?->service_type
                ?? $jasaItem?->jasa?->service_type;
            $serviceTypeLabel = $this->getServiceTypeLabel($serviceType);

            $orderMethod = $jasaItem?->order_method ?? null;
            $orderMethodLabel = $this->getOrderMethodLabel($orderMethod);

            $serviceTitle = $jasaItem?->jasa_title_snapshot ?? $jasaItem?->jasa_title ?? 'Layanan';
            $serviceImage = $jasaItem?->jasa_image_snapshot ?? $jasaItem?->jasa_image_url ?? null;

            // Standard/unified fields
            $invoice = 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);
            $customerName = $order->customer_name_snapshot ?? $order->nama ?? 'Pelanggan';
            $customerPhone = $order->customer_phone_snapshot ?? $order->tel ?? '-';

            $discountTotal = (float) ($order->discount_total ?? 0);
            $voucherInfo = $this->getOrderVoucherInfo($order);
            $totalPayment = (float) ($order->total_payment_snapshot ?? $order->total_price ?? 0);

            return [
                'id' => $order->id,
                'order_id' => $order->id,
                'order_type' => 'jasa',
                'invoice' => $invoice,
                'order_number' => $invoice,
                'formatted_order_number' => $invoice,
                'nomor_pesanan' => $invoice,
                'order_code' => $invoice,
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
                'total_price' => $totalPayment,
                'total' => $totalPayment,
                'total_payment' => $totalPayment,
                'subtotal' => (float) ($order->subtotal_snapshot ?? $order->total_price),
                'platform_fee' => (float) ($order->platform_fee_snapshot ?? 0),
                'admin_fee' => (float) ($order->platform_fee_snapshot ?? 0),
                'discount_total' => $discountTotal,
                'discount' => $discountTotal,
                'voucher_usage_id' => $voucherInfo['voucher_usage_id'],
                'voucher_id' => $voucherInfo['voucher_id'],
                'voucher_code' => $voucherInfo['voucher_code'],
                'voucher_name' => $voucherInfo['voucher_name'],
                'voucher_discount_amount' => $voucherInfo['voucher_discount_amount'],

                // Customer info
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $jasaItem?->service_location_address,
                'customer' => [
                    'id' => $order->user_id,
                    'name' => $customerName,
                    'phone' => $customerPhone,
                ],

                // Jasa info
                'jasa' => [
                    'id' => $jasaItem?->jasa_id,
                    'title' => $serviceTitle,
                    'image' => $jasaItem?->jasa_image_snapshot,
                    'image_url' => $serviceImage,
                ],
                'service_name' => $serviceTitle,
                'service_image' => $serviceImage,
                'service_type' => $serviceType,
                'service_type_label' => $serviceTypeLabel,
                'tipe_layanan' => $serviceTypeLabel,

                // Booking / Order method
                'booking_date' => $jasaItem?->booking_date_snapshot ?? $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time_snapshot ?? $jasaItem?->booking_time,
                'cara_pemesanan' => $orderMethod,
                'cara_pemesanan_label' => $orderMethodLabel,
                'order_method' => $orderMethod,
                'order_method_label' => $orderMethodLabel,

                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
                'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
                'merchant_responded_at' => $order->merchant_responded_at?->toISOString(),
                'completion_submitted_at' => $order->completion_submitted_at?->toISOString(),
                'completion_deadline_at' => $order->completion_deadline_at?->toISOString(),
                'completed_at' => $order->completed_at?->toISOString(),
                'completed_by' => $order->completed_by,
                'auto_completed_at' => $order->auto_completed_at?->toISOString(),
                'completion_evidences' => ($jasaItem?->completionEvidences ?? collect())->map(function ($ev) {
                    return [
                        'id' => $ev->id,
                        'jasa_order_item_id' => $ev->jasa_order_item_id,
                        'file_path' => $ev->file_path,
                        'file_url' => $ev->file_url,
                        'image_url' => $ev->file_url,
                        'url' => $ev->file_url,
                        'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                        'note' => $ev->note ?? null,
                        'created_at' => $ev->created_at?->toISOString(),
                    ];
                })->toArray(),
                'completion_note' => $jasaItem?->completion_note ?? null,
                'has_evidence' => $jasaItem?->completionEvidences?->isNotEmpty() ?? false,
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
     * Get single service order detail (for merchant)
     *
     * @param Request $request
     * @param string $merchantSlug
     * @param int $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function merchantShow(Request $request, string $merchantSlug, int $orderId)
    {
        if (!$orderId || $orderId === 0) {
            return ApiResponse::error('ID pesanan tidak valid', 400);
        }

        $user = Auth::user();

        $merchant = \App\Models\Merchant::where('slug', $merchantSlug)
            ->where('user_id', $user->id)
            ->first();

        if (!$merchant) {
            return ApiResponse::error('Merchant tidak ditemukan', 404);
        }

        $order = Order::with([
            'user:id,name,phone,email',
            'merchant:id,name,slug,logo_path,phone,segmentation_id',
            'merchant.primaryAddress',
            'merchant.primaryAddress.province',
            'merchant.primaryAddress.city',
            'merchant.primaryAddress.district',
            'merchant.primaryAddress.village',
            'jasaItems.jasa:id,title,image,description,service_type',
            'jasaItems.jasa.categories',
            'jasaItems.review',
            'jasaItems.completionEvidences',
            'orderItems.service',
            'payment',
        ])
            ->where('id', $orderId)
            ->where('merchant_id', $merchant->id)
            ->where('order_type', 'jasa')
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Auto-expire if deadline passed (source of truth: orders table)
        $this->autoExpireOrder($order);

        $jasaItem = $order->jasaItems->first();

        $review = null;
        if ($jasaItem) {
            // Primary check: order_id + jasa_order_item_id
            $review = Rating::with(['media'])->where('order_id', $order->id)
                ->where('jasa_order_item_id', $jasaItem->id)
                ->first();

            // Fallback check: jasa_order_item_id
            if (!$review) {
                $review = Rating::with(['media'])->where('jasa_order_item_id', $jasaItem->id)
                    ->first();
            }
        }
        $isReviewed = $review !== null;
        $canReview = false; // Merchant cannot submit review
        $canUpdateReview = false;

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
        $serviceType = $jasaItem?->service_type_snapshot
            ?? $jasaItem?->service_type
            ?? $jasaItem?->jasa?->service_type
            ?? null;
        $serviceTypeLabel = $this->getServiceTypeLabel($serviceType);
        $orderMethod = $jasaItem?->order_method ?? null;
        $categoryName = $jasaItem?->jasa?->categories?->first()?->name ?? null;
        $merchantAddress = $this->getMerchantFullAddress($order);
        $merchantLogoUrl = $this->getMerchantLogoUrl($order->merchant);
        $serviceLocationAddress = $this->getServiceLocationAddress($serviceType, $order, $jasaItem);
        // Get payment info
        $payment = Payment::where('order_id', $orderId)->first();

        // ─── Fee breakdown ────────────────────────────────────────────────────
        // Use saved snapshots if available; otherwise derive for legacy orders
        $isCod = strtoupper($paymentMethod) === 'COD';
        $savedSubtotal = (float) ($order->subtotal_snapshot ?? 0);
        $savedPlatformFee = (float) ($order->platform_fee_snapshot ?? 0);
        $savedTotalPayment = (float) ($order->total_payment_snapshot ?? 0);
        $savedPaymentFee = (float) ($order->payment_fee_snapshot ?? 0);
        $discountTotal = (float) ($order->discount_total ?? 0);
        $voucherInfo = $this->getOrderVoucherInfo($order);

        if ($savedSubtotal > 0 && $savedTotalPayment > 0) {
            // New order: use saved snapshots
            $subtotal = $savedSubtotal;
            $platformFee = $isCod ? 0 : $savedPlatformFee;
            $paymentFee = $savedPaymentFee;
            $totalPayment = $savedTotalPayment;
        } elseif ($savedTotalPayment > 0) {
            // Legacy order: subtotal_snapshot is null, but total_payment_snapshot is set
            // Try to derive service price from jasaOrderItem snapshots
            $legacySubtotal = (float) ($jasaItem?->original_price_snapshot ?? 0);
            if ($legacySubtotal <= 0) {
                $legacySubtotal = (float) ($jasaItem?->jasa_price_snapshot ?? $order->total_price ?? 0);
            }
            $subtotal = $legacySubtotal;
            $totalPayment = $savedTotalPayment;
            $paymentFee = max($totalPayment - $subtotal, 0);
            $platformFee = $isCod ? 0 : (
                $savedPlatformFee > 0 ? $savedPlatformFee : $paymentFee
            );
        } else {
            // Fully legacy order: no snapshots at all
            $subtotal = (float) ($jasaItem?->original_price_snapshot ?? $order->total_price ?? 0);
            $totalPayment = (float) ($order->total_price ?? 0);
            $paymentFee = 0;
            $platformFee = 0;
        }
        // Kept for response compatibility
        $totalPrice = $totalPayment;

        // Transform completion evidences from jasa_order_items relation
        $completionEvidences = $order->jasaItems->flatMap(function ($item) {
            return $item->completionEvidences;
        })->map(function ($ev) {
            return [
                'id' => $ev->id,
                'jasa_order_item_id' => $ev->jasa_order_item_id,
                'file_path' => $ev->file_path,
                'file_url' => $ev->file_url,
                'image_url' => $ev->file_url,
                'url' => $ev->file_url,
                'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                'note' => $ev->note ?? null,
                'created_at' => $ev->created_at?->toISOString(),
            ];
        });

        return ApiResponse::success([
            'id' => $order->id,
            'order_id' => $order->id,
            'order_number' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'invoice' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'nomor_pesanan' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'order_code' => 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
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
            // Fee breakdown
            'subtotal' => $subtotal,
            'payment_fee' => $paymentFee,
            'platform_fee' => $platformFee,
            'discount_total' => $discountTotal,
            'discount' => $discountTotal,
            'voucher_usage_id' => $voucherInfo['voucher_usage_id'],
            'voucher_id' => $voucherInfo['voucher_id'],
            'voucher_code' => $voucherInfo['voucher_code'],
            'voucher_name' => $voucherInfo['voucher_name'],
            'voucher_discount_amount' => $voucherInfo['voucher_discount_amount'],
            'is_cod' => $isCod,
            'total_price' => $totalPrice,
            'total_payment' => $totalPayment,
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
                'logo_url' => $merchantLogoUrl,
                'logoUrl' => $merchantLogoUrl,
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
                'subtotal' => $subtotal,
                'payment_fee' => $paymentFee,
                'discount_total' => $discountTotal,
                'voucher_code' => $voucherInfo['voucher_code'],
                'voucher_name' => $voucherInfo['voucher_name'],
                'total_payment' => $totalPayment,
                'payment_method' => $paymentMethod,
                'payment_channel' => $paymentChannel,
            ] : null,
            'completion_evidences' => $completionEvidences->toArray(),
            'completion_note' => $jasaItem?->completion_note ?? null,
            'review' => $review ? $review->toArray() : null,
            'is_reviewed' => $isReviewed,
            'can_review' => $canReview,
            'can_update_review' => $canUpdateReview,
            'review_updated_count' => $review ? ($review->update_count ?? 0) : 0,
            'is_review_updated' => $review && ($review->update_count ?? 0) >= 1,
            'created_at' => $order->created_at->toISOString(),
        ], 'Order detail fetched');
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

        // Flow jasa baru:
        // Merchant hanya boleh mulai mengerjakan setelah customer membayar.
        // COD dikecualikan karena pembayaran dilakukan di luar Xendit.
        if (
            $newStatus === 'layanan_dikerjakan' &&
            strtoupper((string) ($order->payment_method ?? '')) !== 'COD' &&
            strtoupper((string) ($order->payment_status ?? '')) !== 'PAID'
        ) {
            return ApiResponse::error(
                'Customer belum melakukan pembayaran. Layanan belum dapat diproses.',
                422
            );
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

            if ($newStatus === 'ditolak') {
                $this->releaseVoucherUsage($order);
            }

            $jasaItem = $order->jasaItems->first();
            if ($jasaItem && $newStatus === 'ditolak') {
                $jasaItem->update([
                    'completion_note' => $request->rejection_reason ?? 'Pesanan ditolak',
                ]);
            }

            // Handle completion evidence uploads when marking as menunggu_konfirmasi_selesai or selesai
            if (($newStatus === 'menunggu_konfirmasi_selesai' || $newStatus === 'selesai') && $jasaItem) {
                // SLA: customer harus konfirmasi dalam durasi yang dikonfigurasi
                $orderUpdateData = [];
                if ($newStatus === 'menunggu_konfirmasi_selesai') {
                    $orderUpdateData['completion_submitted_at'] = now();
                    $orderUpdateData['completion_deadline_at'] = now()->addHours((int) config('sla.customer_confirm_hours', 24));
                }
                if (count($orderUpdateData) > 0) {
                    $order->update($orderUpdateData);
                }

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
                        'service_order_id' => null,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
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

        $freshOrder = $order->fresh(['jasaItems.completionEvidences']);
        $freshJasaItem = $freshOrder?->jasaItems->first();
        $freshEvidences = $freshJasaItem?->completionEvidences ?? collect();

        event(new OrderStatusUpdated($freshOrder, $newStatus));

        Log::info('[JasaOrderController] Status updated', [
            'order_id' => $order->id,
            'new_status' => $newStatus,
        ]);

        return ApiResponse::success([
            'id' => $freshOrder->id,
            'order_id' => $freshOrder->id,
            'jasa_order_item_id' => $freshJasaItem?->id,
            'status' => $freshOrder->status,
            'completion_note' => $freshJasaItem?->completion_note,
            'completion_evidences' => $freshEvidences->map(function ($ev) {
                return [
                    'id' => $ev->id,
                    'jasa_order_item_id' => $ev->jasa_order_item_id,
                    'file_path' => $ev->file_path,
                    'file_url' => $ev->file_url,
                    'image_url' => $ev->file_url,
                    'url' => $ev->file_url,
                    'file_type' => $ev->file_type ?? ($ev->is_video ? 'video' : 'image'),
                    'note' => $ev->note ?? null,
                    'created_at' => $ev->created_at?->toISOString(),
                ];
            })->toArray(),
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
        // Get order and its first jasa item
        $order = Order::with('jasaItems')->find($orderId);
        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }
        $jasaItem = $order->jasaItems->first();
        if (!$jasaItem) {
            return ApiResponse::error('Detail pesanan tidak ditemukan', 404);
        }

        // Merge rateable fields to request so RatingController can process it
        $request->merge([
            'order_id' => $orderId,
            'rateable_id' => $jasaItem->jasa_id,
            'rateable_type' => 'App\\Models\\Jasa',
            'merchant_id' => $order->merchant_id,
        ]);

        // Resolve and call RatingController@store
        $storeResponse = app(\App\Http\Controllers\RatingController::class)->store($request);

        // If RatingController returns an error response, return it directly
        if ($storeResponse->getStatusCode() >= 400) {
            return $storeResponse;
        }

        // Otherwise, RatingController was successful and created the rating.
        // Let's get the created rating object from the storeResponse
        $storeData = json_decode($storeResponse->getContent(), true);
        $reviewData = $storeData['data'] ?? [];

        // Build the FE-compatible response expected by the Jasa order review view
        $responseData = [
            'id' => $order->id, // PRIMARY ID
            'order_id' => $order->id,
            'jasa_order_item_id' => $jasaItem->id,
            'rating' => $reviewData['rating'] ?? $request->input('rating'),
            'review' => $reviewData,
            'media' => $reviewData['media'] ?? [],
        ];

        return ApiResponse::success($responseData, 'Review berhasil dikirim. Terima kasih atas ulasan Anda!');
    }

    /**
     * Ambil informasi voucher yang digunakan pada order.
     */
    private function getOrderVoucherInfo(Order $order): array
    {
        $usage = VoucherUsage::with('voucher')
            ->where('order_id', $order->id)
            ->first();

        return [
            'voucher_usage_id' => $usage?->id,
            'voucher_id' => $usage?->voucher_id,
            'voucher_code' => $usage?->voucher?->voucher_code,
            'voucher_name' => $usage?->voucher?->voucher_name,
            'voucher_discount_amount' => $usage ? (float) $usage->discount_amount : (float) ($order->discount_total ?? 0),
        ];
    }

    /**
     * Kembalikan pemakaian voucher jika order batal/ditolak/expired.
     *
     * Sistem voucher menghitung pemakaian dari tabel voucher_usages,
     * jadi cukup hapus usage berdasarkan order_id.
     */
    private function releaseVoucherUsage(Order $order): void
    {
        VoucherUsage::where('order_id', $order->id)->delete();
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
            'expired' => [], // terminal: cannot transition from expired
            default => [],
        };
    }
}
