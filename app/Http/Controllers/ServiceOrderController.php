<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceCompletionEvidence;
use App\Models\Jasa;
use App\Models\Merchant;
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
            'mekanisme_pemesanan' => 'nullable|string|max:50',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'total_price' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:COD,MANUAL,cod,manual',
        ]);

        $jasa = Jasa::with(['merchant', 'images'])->findOrFail($request->jasa_id);

        // VALIDATION: Only allow orders for UMKM Jasa
        if (!$jasa->merchant || $jasa->merchant->segmentation_id !== 3) {
            return ApiResponse::error('Layanan ini tidak tersedia untuk dipesan', 400);
        }

        // VALIDATION: Only allow langsung_pesan or booking services
        // Cannot directly order services that require consultation
        $caraPemesanan = $jasa->cara_pemesanan ?? 'langsung_pesan';
        if (!in_array($caraPemesanan, ['langsung_pesan', 'booking'])) {
            return ApiResponse::error(
                'Layanan ini memerlukan konsultasi terlebih dahulu. Silakan gunakan fitur Ajukan Konsultasi.',
                400
            );
        }

        $customerId = Auth::id();

        // Calculate total price
        $totalPrice = floatval($request->total_price ?? 0);
        if ($totalPrice <= 0) {
            $totalPrice = floatval($jasa->fixed_price ?? $jasa->base_price ?? $jasa->price ?? 0);
        }

        // Create order with initial status: MENUNGGU_KONFIRMASI_MERCHANT
        $serviceOrder = ServiceOrder::create([
            'customer_id' => $customerId,
            'merchant_id' => $jasa->merchant_id,
            'jasa_id' => $jasa->id,
            'service_name' => $jasa->title,
            'service_type' => $jasa->service_type ?? $jasa->service_type_booking ?? null,
            'service_image' => $jasa->coverImage
                ? asset('storage/' . $jasa->coverImage->image_path)
                : ($jasa->image ? asset('storage/' . $jasa->image) : null),
            'merchant_name' => $jasa->merchant->name ?? 'UMKM',
            'total_price' => $totalPrice,
            'status' => ServiceOrder::STATUS_MENUNGGU_KONFIRMASI,
            'booking_date' => $request->booking_date,
            'booking_time' => $request->booking_time,
            'booking_note' => $request->booking_note,
            'mekanisme_pemesanan' => $request->mekanisme_pemesanan ?? null,
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'customer_address' => $request->customer_address,
            'payment_method' => strtoupper($request->payment_method ?? 'COD'),
            'payment_status' => ServiceOrder::PAYMENT_UNPAID,
            'customer_latitude' => $request->latitude,
            'customer_longitude' => $request->longitude,
        ]);

        // NOTE: Untuk checkout layanan jasa, data utama disimpan HANYA di service_orders.
        // Tidak dilakukan bridge ke tabel orders.
        // History customer dan merchant mengambil data dari service_orders.
        // Jika di masa depan perlu sync ke orders (misal laporan gabungan), maka
        // JAWA only syncs fields yang ADA di tabel orders (tanpa mekanisme_pemesanan).

        $serviceOrder->load(['merchant', 'jasa']);

        Log::info('[ServiceOrder Create] Order created', [
            'order_id' => $serviceOrder->id,
            'jasa_id' => $jasa->id,
            'merchant_id' => $jasa->merchant_id,
        ]);

        return ApiResponse::success($serviceOrder, 'Pesanan berhasil dibuat. Menunggu konfirmasi dari merchant.', 201);
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
     */
    public function getCustomerHistory(Request $request)
    {
        $customerId = Auth::id();
        $status = $request->get('status');
        $perPage = $request->get('per_page', 10);

        $query = ServiceOrder::with([
            'jasa',
            'merchant:id,name,slug,logo_path,segmentation_id',
            'review.media',
            'completionEvidences',
        ])
            ->forCustomer($customerId)
            ->orderByDesc('created_at');

        if ($status) {
            $query->withStatus($status);
        }

        $orders = $query->paginate($perPage);

        // Transform to array and ensure completion_evidences and review.media are at top level
        $ordersArray = $orders->toArray();
        $transformedData = collect($orders->items())->map(function ($order) {
            $orderArray = $order->toArray();

            // Transform review.media: ensure file_url is included (accessor may not run in toArray)
            if (isset($order->review) && $order->review->media->count() > 0) {
                $mediaItems = $order->review->media->map(function ($media) {
                    $arr = $media->toArray();
                    if (!isset($arr['file_url']) || $arr['file_url'] === '') {
                        $arr['file_url'] = $media->file_url;
                    }
                    return $arr;
                })->toArray();
                $orderArray['review']['media'] = $mediaItems;
            }

            // Transform completionEvidences for frontend compatibility
            if (isset($order->completionEvidences) && $order->completionEvidences->count() > 0) {
                $evidences = $order->completionEvidences->map(function ($evidence) {
                    $arr = $evidence->toArray();
                    // Ensure file_url is included (accessor might not run in toArray)
                    if (!isset($arr['file_url']) || empty($arr['file_url'])) {
                        $arr['file_url'] = $evidence->file_url; // Accessor will generate it
                    }
                    return $arr;
                })->toArray();
                $orderArray['completion_evidences'] = $evidences;
            } else {
                $orderArray['completion_evidences'] = [];
            }
            return $orderArray;
        })->toArray();

        $ordersArray['data'] = $transformedData;

        return ApiResponse::success($ordersArray, 'success');
    }

    /**
     * Get single service order detail (for customer)
     */
    public function getCustomerOrderDetail(Request $request, int $id)
    {
        $customerId = Auth::id();

        $order = ServiceOrder::with([
            'jasa',
            'merchant:id,name,slug,logo_path,segmentation_id,phone,whatsapp',
            'review.media',
            'completionEvidences',
            'jasa.ratingSummary',
        ])
            ->where('customer_id', $customerId)
            ->find($id);

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        return ApiResponse::success($order, 'success');
    }

    /**
     * Customer confirms that work is completed (after seeing evidence).
     */
    public function confirmCompleted(Request $request, int $id)
    {
        $customerId = Auth::id();

        $order = ServiceOrder::where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        if ($order->status !== ServiceOrder::STATUS_MENUNGGU_SELESAI) {
            return ApiResponse::error(
                'Pesanan tidak dapat dikonfirmasi. Status saat ini: ' . $order->statusLabel,
                400
            );
        }

        // Check that there is at least one completion evidence
        if ($order->completionEvidences()->count() === 0) {
            return ApiResponse::error('Bukti pengerjaan belum tersedia', 400);
        }

        $order->confirmCompleted();

        return ApiResponse::success($order->fresh(['completionEvidences']), 'Pesanan berhasil dikonfirmasi selesai. Terima kasih!');
    }

    /**
     * Get merchant service order history
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

        $status = $request->get('status');
        $perPage = $request->get('per_page', 10);

        $query = ServiceOrder::with([
            'jasa',
            'customer:id,name,phone',
            'review.media',
            'completionEvidences',
        ])
            ->forMerchant($merchant->id)
            ->orderByDesc('created_at');

        if ($status) {
            $query->withStatus($status);
        }

        $orders = $query->paginate($perPage);

        // Transform orders to ensure review.media and completion_evidences are properly serialized
        $ordersArray = $orders->toArray();
        $transformedData = collect($orders->items())->map(function ($order) {
            $orderArray = $order->toArray();

            // Transform review.media: ensure file_url is included (accessor may not run in toArray)
            if (isset($order->review) && $order->review->media->count() > 0) {
                $mediaItems = $order->review->media->map(function ($media) {
                    $arr = $media->toArray();
                    if (!isset($arr['file_url']) || $arr['file_url'] === '') {
                        $arr['file_url'] = $media->file_url;
                    }
                    return $arr;
                })->toArray();
                $orderArray['review']['media'] = $mediaItems;
            }

            // Transform completion_evidences for frontend compatibility
            if (isset($order->completionEvidences) && $order->completionEvidences->count() > 0) {
                $evidences = $order->completionEvidences->map(function ($evidence) {
                    $arr = $evidence->toArray();
                    if (!isset($arr['file_url']) || $arr['file_url'] === '') {
                        $arr['file_url'] = $evidence->file_url;
                    }
                    return $arr;
                })->toArray();
                $orderArray['completion_evidences'] = $evidences;
            } else {
                $orderArray['completion_evidences'] = [];
            }

            return $orderArray;
        })->toArray();

        $ordersArray['data'] = $transformedData;

        return ApiResponse::success($ordersArray, 'success');
    }

    /**
     * Get single service order detail (for merchant)
     */
    public function getMerchantOrderDetail(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $order = ServiceOrder::with([
            'jasa',
            'customer:id,name,phone',
            'review.media',
            'completionEvidences',
        ])
            ->where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        return ApiResponse::success($order, 'success');
    }

    /**
     * Update service order status (merchant actions)
     */
    public function updateStatus(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        if ($merchant->segmentation_id !== 3) {
            return ApiResponse::error('Merchant ini tidak memiliki layanan jasa', 400);
        }

        // Normalize 'completed' → 'selesai' (frontend alias)
        $requestedStatus = $request->input('status');
        if ($requestedStatus === 'completed') {
            $request->merge(['status' => ServiceOrder::STATUS_SELESAI]);
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

        $order = ServiceOrder::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Check if order is in terminal state
        if ($order->isTerminal()) {
            return ApiResponse::error(
                'Pesanan sudah dalam status akhir (' . $order->statusLabel . ') dan tidak dapat diubah lagi.',
                400
            );
        }

        $newStatus = $data['status'];

        // VALIDATION: Validate status transition
        if (!$order->canTransitionTo($newStatus)) {
            return ApiResponse::error(
                "Tidak dapat mengubah status dari '{$order->statusLabel}' ke '" . ServiceOrder::getStatusLabelStatic($newStatus) . "'",
                422
            );
        }

        try {
            DB::beginTransaction();

            // Handle rejection
            if ($newStatus === ServiceOrder::STATUS_DITOLAK) {
                $order->updateStatus($newStatus, [
                    'rejection_reason' => $data['rejection_reason'] ?? 'Merchant menolak pesanan',
                ]);
            }

            // Handle completion evidence upload (DIKERJAKAN → MENUNGGU_SELESAI)
            if ($newStatus === ServiceOrder::STATUS_MENUNGGU_SELESAI) {
                $files = $request->file('evidences', []);
                $uploadedCount = 0;

                foreach ($files as $index => $file) {
                    // Validate file
                    $error = ServiceCompletionEvidence::validateFile($file);
                    if ($error) {
                        throw new \Exception("File {$file->getClientOriginalName()}: {$error}");
                    }

                    $type = ServiceCompletionEvidence::getFileType($file->getMimeType());
                    $path = ServiceCompletionEvidence::generatePath(
                        $file->getClientOriginalName(),
                        $type === 'image' ? 'images' : 'videos'
                    );

                    // Store file
                    Storage::disk('public')->put($path, file_get_contents($file));

                    ServiceCompletionEvidence::create([
                        'service_order_id' => $order->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_url' => Storage::url($path),
                        'file_type' => $type,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'display_order' => $index,
                    ]);

                    $uploadedCount++;
                }

                // Update completion note
                if (!empty($data['completion_note'])) {
                    $order->update(['completion_note' => $data['completion_note']]);
                }

                // Update status (no evidence → evidence uploaded, so OK)
                $order->updateStatus($newStatus);
            }

            // Handle direct completion with evidence (DIKERJAKAN → SELESAI)
            if ($newStatus === ServiceOrder::STATUS_SELESAI) {
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

                    ServiceCompletionEvidence::create([
                        'service_order_id' => $order->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_url' => Storage::url($path),
                        'file_type' => $type,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'display_order' => $index,
                    ]);
                }

                if (!empty($data['completion_note'])) {
                    $order->update(['completion_note' => $data['completion_note']]);
                }

                $order->updateStatus($newStatus);
            }

            // Handle regular status transitions
            if (!in_array($newStatus, [ServiceOrder::STATUS_DITOLAK, ServiceOrder::STATUS_MENUNGGU_SELESAI, ServiceOrder::STATUS_SELESAI])) {
                $order->updateStatus($newStatus);
            }

            DB::commit();

            $order->load(['completionEvidences', 'customer']);

            return ApiResponse::success($order, 'Status berhasil diperbarui');
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ServiceOrder UpdateStatus] Error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal memperbarui status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit service order review (customer action)
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

        $order = ServiceOrder::with(['merchant'])->findOrFail($id);

        // Verify customer owns this order
        if ($order->customer_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses ke pesanan ini', 403);
        }

        // VALIDATION: Order must be completed
        if ($order->status !== ServiceOrder::STATUS_SELESAI) {
            return ApiResponse::error(
                'Pesanan harus selesai terlebih dahulu sebelum memberikan review',
                400
            );
        }

        // VALIDATION: Not already reviewed for this service order
        $existingReview = Rating::where('service_order_id', $order->id)
            ->where('user_id', Auth::id())
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

            // Create rating for the service (jasa) with order reference
            $ratingData = [
                'user_id' => Auth::id(),
                'merchant_id' => $order->merchant_id,
                'service_order_id' => $order->id,
                'rateable_id' => $order->jasa_id,
                'rateable_type' => Jasa::class,
                'rating' => (int) $data['rating'],
                'title' => $data['title'] ?? null,
                'comment' => $data['comment'] ?? null,
                'is_anonymous' => $isAnonymous,
            ];

            Log::info('[submitReview] Creating rating with data:', $ratingData);

            $review = Rating::create($ratingData);

            Log::info('[submitReview] Rating created:', ['id' => $review->id]);

            // Handle media uploads ONLY if files are present
            // Normalize to array: single file UploadedFile → array, multiple → as-is
            if ($request->hasFile('media')) {
                $mediaFiles = $request->file('media');

                // Ensure array: single UploadedFile → [$file], already array → use as-is
                if (!is_array($mediaFiles) && $mediaFiles instanceof \Illuminate\Http\UploadedFile) {
                    $mediaFiles = [$mediaFiles];
                }

                Log::info('[submitReview] Processing media files:', [
                    'count' => count($mediaFiles),
                    'type' => gettype($mediaFiles),
                ]);

                // Ensure storage directory exists
                $basePath = 'service-reviews';
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

                        // Use storeAs — returns the relative path under 'public' disk root
                        // File lands at: storage/app/public/{basePath}/{type}/{dateFolder}/{newFileName}
                        // storeAs returns the relative path: {basePath}/{type}/{dateFolder}/{newFileName}
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
                        // Continue with other media files — don't fail the whole review for one media error
                    }
                }
            } else {
                Log::info('[submitReview] No media files uploaded');
            }

            // Mark order as reviewed
            $order->markAsReviewed($review->id);

            // Update rating summaries
            $this->updateRatingSummary($order->jasa_id, $order->merchant_id);

            DB::commit();

            // Load relationships for response
            $review->load(['user', 'media']);
            $order->load(['review']);

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
     * Update rating summaries for jasa and merchant
     */
    private function updateRatingSummary(int $jasaId, int $merchantId): void
    {
        // Update jasa rating summary
        $jasaRatings = Rating::where('rateable_id', $jasaId)
            ->where('rateable_type', Jasa::class)
            ->get();

        RatingSummary::updateOrCreate(
            ['summaryable_id' => $jasaId, 'summaryable_type' => Jasa::class],
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

        // Update merchant overall rating
        $merchantRatings = Rating::where('merchant_id', $merchantId)->get();

        RatingSummary::updateOrCreate(
            ['summaryable_id' => $merchantId, 'summaryable_type' => Merchant::class],
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
