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
            // COD: langsung tunggu konfirmasi merchant
            // Xendit: tunggu pembayaran dulu
            $initialStatus = $isCodPayment ? 'menunggu_konfirmasi_merchant' : 'pending';

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
                // COD: langsung set confirm_deadline
                'confirm_deadline' => $isCodPayment ? now()->addMinutes($confirmMinutes) : null,
            ]);

            // Create jasa_order_items
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
            'payments',
        ])
            ->where('user_id', $customerId)
            ->where('order_type', 'jasa')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $orders->map(function ($order) {
            $jasaItem = $order->jasaItems->first();
            $payment = $order->payments->first(); // Get first pending payment

            return [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_price' => $order->total_price,
                'merchant' => $order->merchant ? [
                    'id' => $order->merchant->id,
                    'name' => $order->merchant->name,
                    'slug' => $order->merchant->slug,
                ] : null,
                // Payment info for "continue payment" button
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'invoice_url' => $payment->invoice_url,
                    'expired_at' => $payment->expired_at?->toISOString(),
                ] : null,
                'jasa' => $jasaItem?->jasa ? [
                    'id' => $jasaItem->jasa->id,
                    'title' => $jasaItem->jasa->title,
                ] : null,
                'booking_date' => $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
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
        $customerId = Auth::id();

        $order = Order::with([
            'merchant:id,name,slug,logo_path,phone,segmentation_id',
            'merchant.primaryAddress',
            'jasaItems.jasa:id,title,image,description',
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

        // Get payment info
        $payment = Payment::where('order_id', $orderId)->first();

        return ApiResponse::success([
            'order_id' => $order->id,
            'jasa_order_item_id' => $jasaItem?->id,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'payment_channel' => $order->payment_channel,
            'total_price' => $order->total_price,
            'paid_at' => $order->paid_at?->toISOString(),
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            'cancelled_at' => $order->cancelled_at?->toISOString(),
            'merchant' => $order->merchant,
            'jasa' => $jasaItem?->jasa,
            'jasa_order_item' => [
                'id' => $jasaItem?->id,
                'service_type' => $jasaItem?->service_type,
                'booking_date' => $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time,
                'booking_note' => $jasaItem?->booking_note,
                'service_location_address' => $jasaItem?->service_location_address,
                'customer_confirmed' => $jasaItem?->customer_confirmed,
                'is_reviewed' => $jasaItem?->is_reviewed,
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
            ]);
        });

        event(new OrderStatusUpdated($order->fresh(), 'selesai'));

        Log::info('[JasaOrderController] Order completed', [
            'order_id' => $order->id,
        ]);

        return ApiResponse::success([
            'order_id' => $order->id,
            'status' => 'selesai',
        ], 'Pesanan berhasil diselesaikan');
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

        $query = Order::with([
            'jasaItems.jasa:id,title',
            'jasaItems.completionEvidences',
        ])
            ->where('merchant_id', $merchant->id)
            ->where('order_type', 'jasa');

        if ($status) {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('created_at')->paginate($perPage);

        $data = $orders->map(function ($order) {
            $jasaItem = $order->jasaItems->first();

            return [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaItem?->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_price' => $order->total_price,
                'customer_name' => $order->nama,
                'customer_phone' => $order->tel,
                'jasa' => $jasaItem?->jasa ? [
                    'id' => $jasaItem->jasa->id,
                    'title' => $jasaItem->jasa->title,
                ] : null,
                'booking_date' => $jasaItem?->booking_date,
                'booking_time' => $jasaItem?->booking_time,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
                'has_evidence' => $jasaItem?->completionEvidences?->isNotEmpty() ?? false,
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

        DB::transaction(function () use ($order, $newStatus, $request) {
            $order->update(['status' => $newStatus]);

            $jasaItem = $order->jasaItems->first();
            if ($jasaItem && $newStatus === 'ditolak') {
                $jasaItem->update([
                    'completion_note' => $request->rejection_reason,
                ]);
            }
        });

        event(new OrderStatusUpdated($order->fresh(), $newStatus));

        Log::info('[JasaOrderController] Status updated', [
            'order_id' => $order->id,
            'new_status' => $newStatus,
        ]);

        return ApiResponse::success([
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
            'menunggu_konfirmasi_merchant' => ['diterima', 'ditolak'],
            'diterima' => ['layanan_dikerjakan'],
            'layanan_dikerjakan' => ['menunggu_konfirmasi_selesai', 'selesai'],
            'menunggu_konfirmasi_selesai' => ['selesai'],
            default => [],
        };
    }
}