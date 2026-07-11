<?php

namespace App\Http\Controllers;

use App\Models\ServiceConsultation;
use App\Models\ServiceConsultationNote;
use App\Models\Order;
use App\Models\JasaOrderItem;
use App\Models\ConsultationMessage;
use App\Models\ConsultationMessageMedia;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Rating;
use App\Models\RatingSummary;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * ServiceConsultationController
 *
 * Handles consultation flow for services that require consultation.
 * Both customer and merchant can negotiate before creating a service order.
 */
class ServiceConsultationController extends Controller
{
    // ============================================================
    // HELPER METHODS
    // ============================================================

    /**
     * Get status values for a given status group.
     * Maps frontend status_group keys to actual consultation statuses.
     */
    protected function getStatusGroupStatuses(string $group): array
    {
        return match ($group) {
            // Pending - waiting for merchant response
            'pending', 'menunggu' => [
                ServiceConsultation::STATUS_PENDING,
            ],
            // Waiting Customer - merchant sent offer, waiting for customer response
            'waiting_customer', 'dapat_dikerjakan', 'perlu_penyesuaian', 'penyesuaian', 'offer_sent' => [
                ServiceConsultation::STATUS_DAPAT_DIKERJAKAN,
                ServiceConsultation::STATUS_PENYESUAIAN,
            ],
            // Accepted - customer accepted the offer
            'accepted', 'disepakati', 'selesai' => [
                ServiceConsultation::STATUS_ACCEPTED,
            ],
            // Rejected - all closed/rejected consultations
            'rejected', 'ditolak', 'penawaran_ditolak', 'offer_rejected', 'closed', 'ditutup' => [
                ServiceConsultation::STATUS_DITOLAK,
                ServiceConsultation::STATUS_OFFER_REJECTED,
                ServiceConsultation::STATUS_CLOSED,
            ],
            // All statuses
            'all' => [],
            // Default - return empty (no filter)
            default => [],
        };
    }


    /**
     * Attach order/payment summary to consultation JSON response and expire unpaid consultation orders.
     */
    protected function attachConsultationOrderSummary(ServiceConsultation $consultation): ServiceConsultation
    {
        $consultation->loadMissing([
            'merchant.primaryAddress.province',
            'merchant.primaryAddress.city',
            'merchant.primaryAddress.district',
            'merchant.primaryAddress.village',
            'jasaOrderItems.order.payment',
        ]);

        $jasaItem = $consultation->jasaOrderItems->first();
        $order = $jasaItem?->order;
        $payment = $order?->payment;
        $merchantPrimaryAddress = $consultation->merchant?->primaryAddress;

        $merchantAddress = $merchantPrimaryAddress?->full_address
            ?? $merchantPrimaryAddress?->detail
            ?? $consultation->merchant?->address
            ?? $consultation->merchant?->alamat
            ?? null;

        if ($consultation->merchant) {
            $consultation->merchant->setAttribute('address', $merchantAddress);
            $consultation->merchant->setAttribute('alamat', $merchantAddress);
            $consultation->merchant->setAttribute('full_address', $merchantAddress);
        }

        if ($order && !$this->isOrderPaid($order)) {
            $paymentDeadline = $payment?->expired_at ?? $order->confirm_deadline;

            if ($paymentDeadline && now()->greaterThan($paymentDeadline) && !in_array($order->status, ['cancelled', 'rejected', 'completed'], true)) {
                DB::transaction(function () use ($order, $payment, $consultation) {
                    $order->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                    ]);

                    if ($payment && in_array(strtoupper((string) $payment->status), ['PENDING', 'UNPAID'], true)) {
                        $payment->update(['status' => 'EXPIRED']);
                    }

                    if (!in_array($consultation->status, [ServiceConsultation::STATUS_CLOSED, ServiceConsultation::STATUS_OFFER_REJECTED, ServiceConsultation::STATUS_DITOLAK], true)) {
                        $consultation->update([
                            'status' => ServiceConsultation::STATUS_CLOSED,
                            'closed_at' => now(),
                            'offer_status' => 'payment_expired',
                            'negotiation_notes' => trim(($consultation->negotiation_notes ?? '') . "\n[Auto] Percakapan dihentikan karena pembayaran melewati batas waktu 1 jam."),
                        ]);

                        ConsultationMessage::create([
                            'service_consultation_id' => $consultation->id,
                            'sender_id' => $consultation->customer_id,
                            'sender_type' => 'system',
                            'message' => 'Percakapan otomatis dihentikan karena pembayaran tidak diselesaikan dalam 1 jam.',
                            'message_type' => 'payment_expired',
                        ]);
                    }
                });

                $order->refresh();
                $consultation->refresh();
                $consultation->loadMissing(['jasaOrderItems.order.payment']);
                $jasaItem = $consultation->jasaOrderItems->first();
                $order = $jasaItem?->order;
                $payment = $order?->payment;
            }
        }

        $paid = $order ? $this->isOrderPaid($order) : false;
        $deadline = $payment?->expired_at ?? $order?->confirm_deadline;
        $merchantFullAddress = $consultation->merchant?->primaryAddress?->full_address
            ?? $consultation->merchant?->full_address
            ?? $consultation->merchant?->address
            ?? $consultation->merchant?->alamat
            ?? null;

        // Field di bawah ini hanya untuk response API, bukan kolom database.
        // Jangan sampai ikut tersimpan saat method lain memanggil update()/save().
        $computedFields = [
            'order_id' => $order?->id,
            'has_order' => (bool) $order,
            'order_status' => $order?->status,
            'order_payment_status' => $order?->payment_status,
            'payment_status' => $order?->payment_status ?? 'UNPAID',
            'payment_expired_at' => $deadline?->toISOString(),
            'is_paid' => $paid,
            'can_continue_payment' => $this->canContinuePayment($consultation, $order),
            'can_stop_conversation' => $this->canStopConversation($consultation, $order),
            'can_send_message' => $this->canSendConsultationMessage($consultation, $order),
            'conversation_finished' => $order ? in_array($order->status, ['completed'], true) : false,
            'conversation_status_label' => $this->getConversationStatusLabel($consultation, $order),

            'merchant_address' => $merchantFullAddress,
            'merchant_full_address' => $merchantFullAddress,
            'service_location_address' => $merchantFullAddress,
        ];

        foreach ($computedFields as $key => $value) {
            $consultation->setAttribute($key, $value);
            $consultation->syncOriginalAttribute($key);
        }

        return $consultation;
    }

    protected function isOrderPaid(?Order $order): bool
    {
        if (!$order) {
            return false;
        }

        return strtoupper((string) $order->payment_status) === 'PAID';
    }

    protected function canContinuePayment(ServiceConsultation $consultation, ?Order $order = null): bool
    {
        if (in_array($consultation->status, [ServiceConsultation::STATUS_CLOSED, ServiceConsultation::STATUS_DITOLAK, ServiceConsultation::STATUS_OFFER_REJECTED], true)) {
            return false;
        }

        if ($order && $this->isOrderPaid($order)) {
            return false;
        }

        if ($order && in_array($order->status, ['cancelled', 'rejected', 'completed'], true)) {
            return false;
        }

        return !empty($consultation->merchant_offered_price)
            && in_array($consultation->merchant_response, [ServiceConsultation::RESPONSE_BISA, ServiceConsultation::RESPONSE_PENYESUAIAN], true);
    }

    protected function canStopConversation(ServiceConsultation $consultation, ?Order $order = null): bool
    {
        if (in_array($consultation->status, [ServiceConsultation::STATUS_CLOSED, ServiceConsultation::STATUS_DITOLAK, ServiceConsultation::STATUS_OFFER_REJECTED], true)) {
            return false;
        }

        if ($order && $this->isOrderPaid($order)) {
            return false;
        }

        if ($order && in_array($order->status, ['selesai', 'completed'], true)) {
            return false;
        }

        return true;
    }

    protected function canSendConsultationMessage(ServiceConsultation $consultation, ?Order $order = null): bool
    {
        if (in_array($consultation->status, [ServiceConsultation::STATUS_CLOSED, ServiceConsultation::STATUS_DITOLAK, ServiceConsultation::STATUS_OFFER_REJECTED], true)) {
            return false;
        }

        if ($order && in_array($order->status, ['selesai', 'completed'], true)) {
            return false;
        }

        return true;
    }

    protected function getConversationStatusLabel(ServiceConsultation $consultation, ?Order $order = null): string
    {
        if ($consultation->status === ServiceConsultation::STATUS_CLOSED) {
            return ($consultation->offer_status === 'payment_expired') ? 'Percakapan Dihentikan' : 'Percakapan Dihentikan';
        }

        if ($consultation->status === ServiceConsultation::STATUS_DITOLAK) {
            return 'Ditolak Merchant';
        }

        if ($consultation->status === ServiceConsultation::STATUS_OFFER_REJECTED) {
            return 'Penawaran Ditolak';
        }

        if ($order) {
            $paid = $this->isOrderPaid($order);
            if (!$paid) {
                return 'Pengajuan';
            }

            return match ($order->status) {
                'layanan_dikerjakan', 'dikerjakan', 'processing' => 'Layanan Diproses',
                'menunggu_konfirmasi_selesai', 'menunggu_selesai' => 'Menunggu Persetujuan',
                'selesai', 'completed' => 'Selesai',
                default => 'Pembayaran Berhasil',
            };
        }

        if (!empty($consultation->merchant_offered_price) || in_array($consultation->status, [ServiceConsultation::STATUS_DAPAT_DIKERJAKAN, ServiceConsultation::STATUS_PENYESUAIAN], true)) {
            return 'Pengajuan';
        }

        return 'Chat Dengan Merchant';
    }

    // ============================================================
    // CUSTOMER ACTIONS
    // ============================================================

    /**
     * Customer creates a new consultation request
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'jasa_id' => 'required|exists:jasas,id',
            'customer_description' => 'required|string|max:2000',
            'customer_budget' => 'nullable|numeric|min:0',
            'customer_deadline' => 'nullable|date|after:today',
            'customer_note' => 'nullable|string|max:1000',
            'media' => 'nullable|array|max:5',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:51200',
        ]);

        $jasa = Jasa::with('merchant')->findOrFail($request->jasa_id);

        // VALIDATION: Only for UMKM Jasa
        if (!$jasa->merchant || $jasa->merchant->segmentation_id !== 3) {
            return ApiResponse::error('Layanan ini tidak tersedia untuk dikonsultasi', 400);
        }

        // VALIDATION: Only for services that require consultation
        if ($jasa->cara_pemesanan !== 'memerlukan_konsultasi') {
            return ApiResponse::error(
                'Layanan ini dapat dipesan langsung tanpa konsultasi.',
                400
            );
        }

        $customerId = Auth::id();

        // Calculate original price
        $originalPrice = floatval($jasa->fixed_price ?? $jasa->base_price ?? $jasa->price ?? 0);

        try {
            DB::beginTransaction();

            $consultation = ServiceConsultation::create([
                'jasa_id' => $jasa->id,
                'customer_id' => $customerId,
                'merchant_id' => $jasa->merchant_id,
                'service_name' => $jasa->title,
                'original_price' => $originalPrice,
                'customer_description' => $data['customer_description'],
                'customer_budget' => $data['customer_budget'] ?? null,
                'customer_deadline' => $data['customer_deadline'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'status' => ServiceConsultation::STATUS_PENDING,
                'payment_status' => 'unpaid',
            ]);

            // Create the first message — captures the customer's initial request description
            $firstMessage = ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => $customerId,
                'sender_type' => 'customer',
                'message' => $data['customer_description'],
                'message_type' => 'initial',
            ]);

            // Store media uploads linked to the first message (not ServiceConsultationMedia anymore)
            $mediaFiles = $request->file('media') ?? [];
            foreach ($mediaFiles as $index => $file) {
                $type = str_starts_with($file->getMimeType(), 'image/') ? 'images' : 'videos';
                $path = ConsultationMessageMedia::generatePath($file->getClientOriginalName(), $type);

                Storage::disk('public')->put($path, file_get_contents($file));

                ConsultationMessageMedia::create([
                    'consultation_message_id' => $firstMessage->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_url' => Storage::url($path),
                    'file_type' => $type === 'images' ? 'image' : 'video',
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'display_order' => $index,
                ]);
            }

            DB::commit();

            $consultation->load(['messages.media', 'jasa']);

            return ApiResponse::success($consultation, 'Konsultasi berhasil diajukan. Menunggu tanggapan dari merchant.', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Consultation Store] Error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal mengirim konsultasi: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer gets their consultation history
     */
    public function getCustomerHistory(Request $request)
    {
        $customerId = Auth::id();
        $status = $request->get('status');
        $statusGroup = $request->get('status_group');
        $perPage = $request->get('per_page', 10);

        $query = ServiceConsultation::with([
            'jasa:id,title,base_price,fixed_price',
            'merchant:id,name,slug',
            'messages.media',
            'notes',
            'jasaOrderItems.order.payment',
        ])
            ->forCustomer($customerId)
            ->orderByDesc('created_at');

        // Filter by status_group (menunggu, negosiasi, selesai)
        if ($statusGroup) {
            $statuses = $this->getStatusGroupStatuses($statusGroup);
            $query->whereIn('status', $statuses);
        } elseif ($status) {
            $query->where('status', $status);
        }

        $consultations = $query->paginate($perPage);
        $consultations->getCollection()->transform(fn($item) => $this->attachConsultationOrderSummary($item));

        return ApiResponse::success($consultations, 'success');
    }

    /**
     * Customer gets consultation detail
     */
    public function show(int $id)
    {
        try {
            $consultation = ServiceConsultation::with([
                'jasa',
                'merchant:id,name,slug,phone',
                'messages.media',
                'jasaOrderItems.order.payment',
            ])->find($id);

            if (!$consultation) {
                return ApiResponse::error('Konsultasi tidak ditemukan', 404);
            }

            // Customer can only see their own consultations
            if ($consultation->customer_id !== Auth::id()) {
                return ApiResponse::error('Tidak memiliki akses', 403);
            }

            return ApiResponse::success($this->attachConsultationOrderSummary($consultation), 'success');
        } catch (\Throwable $e) {
            Log::error('[CustomerShowConsultation] Error', [
                'consultation_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Gagal memuat detail konsultasi', 500);
        }
    }

    /**
     * Customer adds a note/message to consultation
     */
    public function addNote(Request $request, int $id)
    {
        $data = $request->validate([
            'note' => 'required|string|max:500',
        ]);

        $consultation = ServiceConsultation::find($id);

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if ($consultation->customer_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        if (!$consultation->isActive()) {
            return ApiResponse::error('Konsultasi sudah tidak aktif', 400);
        }

        $consultation->addNote(Auth::id(), 'customer', $data['note']);

        return ApiResponse::success($consultation->fresh(['notes']), 'Catatan berhasil dikirim');
    }

    /**
     * Customer sends a message with optional media attachments.
     * POST /api/service-consultations/{id}/messages
     */
    public function sendMessage(Request $request, int $id)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
            'media' => 'nullable|array|max:5',
            'media.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov,webm|max:51200',
            'proposed_price' => 'nullable|numeric|min:0',
        ]);

        $consultation = ServiceConsultation::find($id);

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if ($consultation->customer_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $consultation->loadMissing(['jasaOrderItems.order.payment']);
        $order = $consultation->jasaOrderItems->first()?->order;

        if (!$this->canSendConsultationMessage($consultation, $order)) {
            return ApiResponse::error('Percakapan sudah selesai atau tidak aktif', 400);
        }

        // Check if message or media is provided
        $hasMessage = !empty(trim($data['message'] ?? ''));
        $hasMedia = !empty($request->file('media'));

        if (!$hasMessage && !$hasMedia) {
            return ApiResponse::error('Pesan atau media wajib diisi', 400);
        }

        try {
            DB::beginTransaction();

            // Create the message
            $message = ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => Auth::id(),
                'sender_type' => 'customer',
                'message' => $hasMessage ? trim($data['message']) : null,
                'proposed_price' => $data['proposed_price'] ?? null,
            ]);

            // Handle media uploads
            $mediaFiles = $request->file('media') ?? [];
            foreach ($mediaFiles as $index => $file) {
                $this->storeMessageMedia($message, $file, $index);
            }

            DB::commit();

            // Load the message with media for response
            $message->load('media');

            return ApiResponse::success($message, 'Pesan berhasil dikirim', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CustomerSendMessage] Error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal mengirim pesan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a media file for a consultation message.
     */
    protected function storeMessageMedia(ConsultationMessage $message, $file, int $index): ConsultationMessageMedia
    {
        $mimeType = $file->getMimeType();
        $isImage = str_starts_with($mimeType, 'image/');
        $isVideo = str_starts_with($mimeType, 'video/');

        $directory = $isImage ? 'consultation-messages/images' : 'consultation-messages/videos';
        $fileType = $isImage ? 'image' : 'video';

        // Store the file
        $path = $file->store($directory, 'public');

        return ConsultationMessageMedia::create([
            'consultation_message_id' => $message->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_url' => asset('storage/' . $path),
            'file_type' => $fileType,
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
            'display_order' => $index,
        ]);
    }

    // ============================================================
    // MERCHANT ACTIONS
    // ============================================================

    /**
     * Merchant gets consultation history
     */
    public function getMerchantHistory(Request $request, Merchant $merchant)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        // VALIDATION: Only for UMKM Jasa
        if ($merchant->segmentation_id !== 3) {
            return ApiResponse::error('Merchant ini tidak memiliki layanan jasa', 400);
        }

        Log::info('[getMerchantHistory] Request received', [
            'merchant_id' => $merchant->id,
            'params' => $request->all(),
        ]);

        $status = $request->get('status');
        $statusGroup = $request->get('status_group');
        $sortBy = $request->get('sort_by', 'newest');
        $perPage = $request->get('per_page', 10);

        // Date filters
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        // Price filters
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');

        $query = ServiceConsultation::with([
            'jasa:id,title,base_price,fixed_price',
            'customer:id,name,phone',
            'messages.media',
            'notes',
            'jasaOrderItems.order.payment',
        ])
            ->forMerchant($merchant->id);

        // Filter by status_group (menunggu, negosiasi, selesai)
        if ($statusGroup) {
            $statuses = $this->getStatusGroupStatuses($statusGroup);
            $query->whereIn('status', $statuses);
        } elseif ($status) {
            $query->where('status', $status);
        }

        // Filter by date range
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Filter by price range
        // Use relevant price: offered_price > final_price > initial_price
        if ($minPrice !== null && $minPrice !== '') {
            $query->where(function ($q) use ($minPrice) {
                $q->where('merchant_offered_price', '>=', $minPrice)
                    ->orWhere('negotiated_price', '>=', $minPrice)
                    ->orWhere(function ($subQ) use ($minPrice) {
                        $subQ->whereNull('merchant_offered_price')
                            ->where('original_price', '>=', $minPrice);
                    });
            });
        }
        if ($maxPrice !== null && $maxPrice !== '') {
            $query->where(function ($q) use ($maxPrice) {
                $q->where('merchant_offered_price', '<=', $maxPrice)
                    ->orWhere('negotiated_price', '<=', $maxPrice)
                    ->orWhere(function ($subQ) use ($maxPrice) {
                        $subQ->whereNull('merchant_offered_price')
                            ->where('original_price', '<=', $maxPrice);
                    });
            });
        }

        // Sorting
        switch ($sortBy) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'date':
                // Sort by consultation date (customer_deadline) or created_at
                $query->orderByRaw("COALESCE(customer_deadline, created_at) DESC");
                break;
            case 'price':
                // Sort by offered price - try offered_price first, then final_price, then total_price
                $query->orderByRaw("COALESCE(merchant_offered_price, negotiated_price, original_price, 0) DESC");
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $consultations = $query->paginate($perPage);

        // Append computed order/payment summary and frontend convenience fields
        $consultations->getCollection()->transform(function ($consultation) {
            $consultation->service_image = $consultation->jasa?->cover_img?->url
                ?? $consultation->jasa?->image_url
                ?? null;
            $consultation->customer_name = $consultation->customer?->name ?? null;

            return $this->attachConsultationOrderSummary($consultation);
        });

        Log::info('[getMerchantHistory] Response built', [
            'total' => $consultations->total(),
            'current_page' => $consultations->currentPage(),
        ]);

        return ApiResponse::success($consultations, 'success');
    }

    /**
     * Merchant gets consultation detail
     */
    public function merchantShow(Request $request, Merchant $merchant, int $id)
    {
        try {
            if ($merchant->user_id !== Auth::id()) {
                return ApiResponse::error('Tidak memiliki akses', 403);
            }

            $consultation = ServiceConsultation::with([
                'jasa',
                'customer:id,name,phone',
                'messages.media',
                'jasaOrderItems.order.payment',
            ])
                ->where('merchant_id', $merchant->id)
                ->where('id', $id)
                ->first();

            if (!$consultation) {
                return ApiResponse::error('Konsultasi tidak ditemukan', 404);
            }

            // Append service_image for frontend convenience
            $consultation->service_image = $consultation->jasa?->cover_img?->url
                ?? $consultation->jasa?->image_url
                ?? null;

            return ApiResponse::success($this->attachConsultationOrderSummary($consultation), 'success');
        } catch (\Throwable $e) {
            Log::error('[MerchantShowConsultation] Error', [
                'merchant_slug' => $merchant->slug,
                'consultation_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Gagal memuat detail konsultasi', 500);
        }
    }

    /**
     * Merchant responds to a consultation request
     *
     * Options:
     * - bisa_dikerjakan: can be done, may include price
     * - perlu_penyesuaian: can be done with adjustments
     * - tidak_bisa_dikerjakan: cannot be done
     */
    public function respond(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        // Find consultation belonging to this merchant
        $consultation = ServiceConsultation::with(['jasa'])
            ->where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$consultation) {
            Log::warning('[respond] Consultation not found', ['id' => $id, 'merchant_id' => $merchant->id]);
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        // Validate request data
        $data = $request->validate([
            'response' => 'required|in:bisa_dikerjakan,perlu_penyesuaian,tidak_bisa_dikerjakan',
            'merchant_offered_price' => 'nullable|numeric|min:1',
            'merchant_note' => 'nullable|string|max:1000',
        ]);

        // ============================================================
        // PRICE VALIDATION: Offer must be greater than starting price
        // ============================================================
        $offeredPrice = $data['merchant_offered_price'] ?? null;
        if ($offeredPrice !== null && $offeredPrice !== '') {
            // Get starting price from jasa (base_price takes priority, then price, then fixed_price)
            $startingPrice = $consultation->jasa?->base_price
                ?? $consultation->jasa?->price
                ?? $consultation->jasa?->fixed_price
                ?? 0;

            // If consultation has original_price (customer's budget), use that as reference too
            $referencePrice = $consultation->original_price
                ? max($startingPrice, floatval($consultation->original_price))
                : $startingPrice;

            // Offer must be greater than the reference price (starting price)
            if (floatval($offeredPrice) <= $referencePrice) {
                $formattedRefPrice = 'Rp ' . number_format($referencePrice, 0, ',', '.');
                return ApiResponse::error(
                    "Harga penawaran harus lebih besar dari harga mulai layanan ({$formattedRefPrice})",
                    422
                );
            }
        }

        // Check if consultation can receive response
        if (!$consultation->canRespond()) {
            Log::warning('[respond] Cannot respond', [
                'consultation_id' => $id,
                'status' => $consultation->status,
                'customer_accepted' => $consultation->customer_accepted,
            ]);
            return ApiResponse::error(
                'Penawaran tidak dapat dikirim karena konsultasi sudah selesai atau tidak aktif. Status: ' . $consultation->status,
                400
            );
        }

        $ok = $consultation->respond(
            $data['response'],
            $data['merchant_offered_price'] ?? null,
            $data['merchant_note'] ?? null
        );

        if (!$ok) {
            Log::error('[MerchantRespond] Failed to respond', [
                'consultation_id' => $consultation->id,
            ]);
            return ApiResponse::error('Gagal mengirim tanggapan', 500);
        }

        if (in_array($data['response'], [ServiceConsultation::RESPONSE_BISA, ServiceConsultation::RESPONSE_PENYESUAIAN], true)) {
            $consultation->update([
                'offer_status' => 'offered',
                'customer_accepted' => false,
                'customer_accepted_at' => null,
                'negotiated_price' => $data['merchant_offered_price'] ?? null,
            ]);
        }

        // Create system message for the offer response
        $responseType = $data['response'];
        $offeredPrice = $data['merchant_offered_price'] ?? null;
        $merchantNote = $data['merchant_note'] ?? null;

        $messageText = '';
        if ($responseType === 'bisa_dikerjakan' || $responseType === 'perlu_penyesuaian') {
            if ($offeredPrice) {
                $formattedPrice = 'Rp ' . number_format($offeredPrice, 0, ',', '.');
                $messageText = "Merchant mengajukan penawaran harga {$formattedPrice}";
                if ($merchantNote) {
                    $messageText .= ". Catatan: {$merchantNote}";
                }
            } else {
                $messageText = "Merchant menyatakan layanan dapat dikerjakan";
                if ($merchantNote) {
                    $messageText .= ". Catatan: {$merchantNote}";
                }
            }
        } elseif ($responseType === 'tidak_bisa_dikerjakan') {
            $messageText = "Merchant tidak dapat mengerjakan layanan ini";
            if ($merchantNote) {
                $messageText .= ". Alasan: {$merchantNote}";
            }
        }

        // Create the chat message
        $chatMessage = ConsultationMessage::create([
            'service_consultation_id' => $consultation->id,
            'sender_id' => Auth::id(),
            'sender_type' => 'merchant',
            'message' => $messageText,
            'proposed_price' => $offeredPrice,
        ]);

        return ApiResponse::success($consultation->fresh(['notes', 'messages.media']), 'Tanggapan berhasil dikirim');
    }

    /**
     * Merchant adds a note/message to consultation
     */
    public function merchantAddNote(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $data = $request->validate([
            'note' => 'required|string|max:500',
        ]);

        $consultation = ServiceConsultation::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if (!$consultation->isActive()) {
            return ApiResponse::error('Konsultasi sudah tidak aktif', 400);
        }

        $consultation->addNote(Auth::id(), 'merchant', $data['note']);

        return ApiResponse::success($consultation->fresh(['notes']), 'Catatan berhasil dikirim');
    }

    /**
     * Merchant sends a message with optional media attachments.
     * POST /api/merchant/{merchantSlug}/service-consultations/{id}/messages
     */
    public function merchantSendMessage(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
            'media' => 'nullable|array|max:5',
            'media.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov,webm|max:51200',
            'proposed_price' => 'nullable|numeric|min:0',
        ]);

        $consultation = ServiceConsultation::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        $consultation->loadMissing(['jasaOrderItems.order.payment']);
        $order = $consultation->jasaOrderItems->first()?->order;

        if (!$this->canSendConsultationMessage($consultation, $order)) {
            return ApiResponse::error('Percakapan sudah selesai atau tidak aktif', 400);
        }

        // Check if message or media is provided
        $hasMessage = !empty(trim($data['message'] ?? ''));
        $hasMedia = !empty($request->file('media'));

        if (!$hasMessage && !$hasMedia) {
            return ApiResponse::error('Pesan atau media wajib diisi', 400);
        }

        try {
            DB::beginTransaction();

            // Create the message
            $message = ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => Auth::id(),
                'sender_type' => 'merchant',
                'message' => $hasMessage ? trim($data['message']) : null,
                'proposed_price' => $data['proposed_price'] ?? null,
            ]);

            // Handle media uploads
            $mediaFiles = $request->file('media') ?? [];
            foreach ($mediaFiles as $index => $file) {
                $this->storeMessageMedia($message, $file, $index);
            }

            DB::commit();

            // Load the message with media for response
            $message->load('media');

            return ApiResponse::success($message, 'Pesan berhasil dikirim', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MerchantSendMessage] Error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal mengirim pesan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Merchant accepts the customer offer and creates a service order.
     * Creates order in orders table (PRIMARY) + jasa_order_items.
     * POST /api/merchant/{merchantSlug}/service-consultations/{id}/accept
     */
    public function merchantAccept(Request $request, Merchant $merchant, int $id)
    {
        if ($merchant->user_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $consultation = ServiceConsultation::with('jasa')
            ->where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        // Only allow acceptance if status is dapat_dikerjakan or perlu_penyesuaian
        if (!in_array($consultation->status, [ServiceConsultation::STATUS_DAPAT_DIKERJAKAN, ServiceConsultation::STATUS_PENYESUAIAN])) {
            return ApiResponse::error('Konsultasi tidak dapat diterima', 400);
        }

        // Check if customer has accepted the offer
        if (!$consultation->customer_accepted) {
            return ApiResponse::error('Pelanggan belum menerima penawaran', 400);
        }

        $jasa = $consultation->jasa;
        $deliveryType = $jasa->delivery_type ?? 'in-store';
        $finalPrice = $consultation->negotiated_price ?? $consultation->merchant_offered_price ?? $consultation->original_price;
        $paymentMethod = 'COD';
        $platformFee = 0;
        $grossAmount = $finalPrice + $platformFee;

        // Generate order code
        $orderCode = 'JSA-' . strtoupper(substr(md5(uniqid()), 0, 10));

        // Get customer info
        $customer = $consultation->customer;
        $customerName = $customer?->name ?? 'Pelanggan';
        $customerPhone = $customer?->phone ?? '';

        // Get service image snapshot
        $serviceImageSnapshot = $jasa->coverImage ? $jasa->coverImage->image_path : null;

        try {
            DB::beginTransaction();

            // ===== 1. Create order in orders table (PRIMARY SOURCE) =====
            $order = Order::create([
                'user_id' => $consultation->customer_id,
                'merchant_id' => $consultation->merchant_id,
                'order_type' => 'jasa',
                'order_code' => $orderCode,
                'subtotal' => $finalPrice,
                'discount_total' => 0,
                'platform_fee' => $platformFee,
                'gross_amount' => $grossAmount,
                'net_amount' => $finalPrice,
                'delivery_type' => $deliveryType,
                'delivery_fee_snapshot' => 0,
                'payment_method' => $paymentMethod,
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'confirm_deadline' => strtoupper($paymentMethod) === 'COD' ? now()->addMinutes(30) : null,
                'user_name_snapshot' => $customerName,
                'user_phone_snapshot' => $customerPhone,
                'address_detail_snapshot' => '',
                'province_name_snapshot' => '',
                'city_name_snapshot' => '',
                'district_name_snapshot' => '',
                'village_name_snapshot' => '',
                'notes' => $consultation->proposed_notes ?? $consultation->negotiation_notes,
            ]);

            // ===== 2. Create jasa_order_items =====
            $jasaOrderItem = JasaOrderItem::create([
                'order_id' => $order->id,
                'jasa_id' => $consultation->jasa_id,
                'service_consultation_id' => $consultation->id,
                'quantity' => 1,
                'price' => $finalPrice,
                'subtotal' => $finalPrice,
                'booking_date' => $consultation->proposed_date,
                'booking_time' => $consultation->proposed_time,
                'order_method' => 'konsultasi',
                'jasa_title_snapshot' => $jasa->title,
                'jasa_image_snapshot' => $serviceImageSnapshot,
                'jasa_price_snapshot' => $jasa->base_price ?? $jasa->price ?? $jasa->fixed_price,
            ]);

            // ===== 3. Update consultation status =====
            // Relasi order konsultasi disimpan melalui jasa_order_items.service_consultation_id.
            // Tabel service_consultations tidak memiliki kolom order_id.
            $consultation->status = ServiceConsultation::STATUS_ACCEPTED;
            $consultation->save();

            DB::commit();

            Log::info('[MerchantAccept] Order created from consultation', [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'consultation_id' => $consultation->id,
                'merchant_id' => $consultation->merchant_id,
                'customer_id' => $consultation->customer_id,
            ]);

            // Load relasi untuk response
            $order->load(['merchant', 'jasaItems.jasa']);

            return ApiResponse::success([
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'status' => $order->status,
                'payment_method' => $paymentMethod,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            ], 'Pesanan berhasil dibuat. Menunggu konfirmasi Anda.', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MerchantAccept] Error', ['error' => $e->getMessage()]);
            return ApiResponse::error('Gagal menerima penawaran: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer accepts the merchant's offer.
     * Marks the offer as accepted and updates consultation status.
     *
     * Valid statuses to accept:
     * - pending (MENUNGGU_RESPON_MERCHANT)
     * - dapat_dikerjakan (BISA_DIKERJAKAN)
     * - perlu_penyesuaian (PERLU_PENYESUAIAN)
     *
     * Invalid statuses (will be rejected):
     * - accepted (DISEPAKATI)
     * - ditolak (DITOLAK)
     * - closed (DITUTUP)
     */
    public function customerRespond(Request $request, int $id)
    {
        Log::info('[CustomerRespond] Request received', [
            'consultation_id' => $id,
            'user_id' => Auth::id(),
        ]);

        $data = $request->validate([
            'response' => 'required|in:accept,reject',
            'customer_note' => 'nullable|string|max:500',
        ]);

        $consultation = ServiceConsultation::with(['jasa', 'merchant'])->find($id);

        if (!$consultation) {
            Log::warning('[CustomerRespond] Consultation not found', ['id' => $id]);
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if ($consultation->customer_id !== Auth::id()) {
            Log::warning('[CustomerRespond] Access denied', [
                'consultation_id' => $id,
                'owner_id' => $consultation->customer_id,
                'requester_id' => Auth::id(),
            ]);
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $action = $data['response'];
        $note = $data['customer_note'] ?? null;

        if ($action === 'reject') {
            $consultation->update([
                'status' => ServiceConsultation::STATUS_OFFER_REJECTED,
            ]);

            ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => Auth::id(),
                'sender_type' => 'system',
                'message' => $note
                    ? "Customer menolak penawaran harga dari merchant. Alasan: {$note}"
                    : "Customer menolak penawaran harga dari merchant.",
                'message_type' => 'offer_rejected',
            ]);

            Log::info('[CustomerRespond] Offer rejected by customer', ['consultation_id' => $id]);
            return ApiResponse::success($consultation->fresh(['notes', 'messages.media']), 'Penawaran berhasil ditolak.');
        }

        if ($action === 'accept') {
            $consultation->update([
                'status' => ServiceConsultation::STATUS_OFFER_ACCEPTED,
            ]);

            ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => Auth::id(),
                'sender_type' => 'system',
                'message' => "Customer menerima penawaran harga dari merchant.",
                'message_type' => 'offer_accepted',
            ]);

            Log::info('[CustomerRespond] Offer accepted by customer', ['consultation_id' => $id]);
            return ApiResponse::success($consultation->fresh(['notes', 'messages.media']), 'Penawaran berhasil diterima.');
        }

        return ApiResponse::error('Aksi tidak valid', 422);
    }

    public function acceptOffer(Request $request, int $id)
    {
        Log::info('[AcceptOffer] Request received', [
            'consultation_id' => $id,
            'user_id' => Auth::id(),
        ]);

        $consultation = ServiceConsultation::with(['jasa', 'merchant', 'customer', 'messages.media', 'jasaOrderItems.order.payment'])->find($id);

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if ($consultation->customer_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $this->attachConsultationOrderSummary($consultation);

        if (!$this->canContinuePayment($consultation, $consultation->jasaOrderItems->first()?->order)) {
            return ApiResponse::error('Penawaran sudah tidak dapat diproses.', 422);
        }

        if (!$consultation->merchant_response) {
            return ApiResponse::error('Belum ada penawaran dari merchant', 400);
        }

        if ($consultation->merchant_response === ServiceConsultation::RESPONSE_TIDAK) {
            return ApiResponse::error('Merchant tidak dapat mengerjakan layanan ini', 400);
        }

        if (!$consultation->merchant_offered_price) {
            return ApiResponse::error('Tidak ada harga yang ditawarkan merchant', 400);
        }

        $consultation->update([
            'negotiated_price' => $consultation->merchant_offered_price,
            'offer_status' => 'waiting_payment',
        ]);

        $alreadyNoted = ConsultationMessage::where('service_consultation_id', $consultation->id)
            ->where('message_type', 'offer_accepted_waiting_payment')
            ->exists();

        if (!$alreadyNoted) {
            $formattedPrice = 'Rp ' . number_format((float) $consultation->merchant_offered_price, 0, ',', '.');
            ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => Auth::id(),
                'sender_type' => 'customer',
                'message' => "Pelanggan menerima penawaran {$formattedPrice} dan diarahkan ke ringkasan pesanan untuk pembayaran.",
                'message_type' => 'offer_accepted_waiting_payment',
                'proposed_price' => $consultation->merchant_offered_price,
            ]);
        }

        $fresh = $consultation->fresh(['jasa', 'merchant', 'customer', 'messages.media', 'jasaOrderItems.order.payment']);

        return ApiResponse::success([
            'consultation' => $this->attachConsultationOrderSummary($fresh),
            'checkout_url' => '/customer/consultations/' . $consultation->id . '/checkout',
            'final_price' => (float) $consultation->merchant_offered_price,
        ], 'Penawaran diterima. Silakan lanjut ke ringkasan pesanan untuk pembayaran.', 200);
    }

    /**
     * Generate WhatsApp URL for booking notification
     */
    protected function generateBookingWhatsAppUrl(ServiceConsultation $consultation): string
    {
        $merchant = $consultation->merchant;
        $phone = $merchant?->phone ?? $merchant?->whatsapp ?? null;

        if (!$phone) {
            return null;
        }

        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (!str_starts_with($phone, '62')) {
            if (str_starts_with($phone, '0')) {
                $phone = '62' . substr($phone, 1);
            } else {
                $phone = '62' . $phone;
            }
        }

        // Build message
        $message = "Halo, saya sudah menerima penawaran harga untuk layanan:\n\n";
        $message .= "Nama Layanan: {$consultation->service_name}\n";
        $message .= "Harga Kesepakatan: Rp " . number_format($consultation->negotiated_price, 0, ',', '.') . "\n";

        if ($consultation->proposed_date) {
            $dateDisplay = \Carbon\Carbon::parse($consultation->proposed_date)->locale('id_ID')->format('d F Y');
            $message .= "Tanggal: {$dateDisplay}\n";
        }

        if ($consultation->proposed_time) {
            $timeDisplay = \Carbon\Carbon::parse($consultation->proposed_time)->format('H:i') . ' WIB';
            $message .= "Jam: {$timeDisplay}\n";
        }

        if ($consultation->proposed_notes) {
            $message .= "Catatan: {$consultation->proposed_notes}\n";
        }

        $message .= "\nMohon konfirmasi booking saya.";

        return 'https://wa.me/' . $phone . '?text=' . urlencode($message);
    }

    /**
     * Customer books the service after price agreement.
     * Creates order in orders table (PRIMARY) + jasa_order_items.
     * Returns WhatsApp URL for payment notification.
     * POST /api/service-consultations/{id}/book
     */
    public function bookConsultation(Request $request, int $id)
    {
        $consultation = ServiceConsultation::with(['jasa', 'merchant', 'customer'])->find($id);

        if (!$consultation) {
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if ($consultation->customer_id !== Auth::id()) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        // Must be in accepted status (price agreed)
        if ($consultation->status !== ServiceConsultation::STATUS_ACCEPTED) {
            return ApiResponse::error(
                'Konsultasi belum disepakati. Status saat ini: ' . $consultation->status,
                400
            );
        }

        // Prevent double booking - check if order already exists
        // NOTE: Query through jasa_order_items since orders.jasa_id is no longer used
        $existingOrder = Order::where('user_id', $consultation->customer_id)
            ->where('order_type', 'jasa')
            ->whereHas('jasaItems', function ($q) use ($consultation) {
                $q->where('jasa_id', $consultation->jasa_id)
                    ->where('order_method', 'consultation');
            })
            ->first();

        if ($existingOrder) {
            Log::info('[bookConsultation] Order already exists - returning existing order', [
                'consultation_id' => $id,
                'order_id' => $existingOrder->id,
            ]);

            return ApiResponse::success([
                'order_id' => $existingOrder->id,
                'jasa_order_item_id' => $existingOrder->jasaItems->first()?->id,
                'whatsapp_url' => null,
                'already_exists' => true,
            ], 'Booking sudah pernah dibuat sebelumnya.', 200);
        }

        // Validate final price exists
        if (!$consultation->negotiated_price && !$consultation->merchant_offered_price) {
            return ApiResponse::error('Harga kesepakatan belum ada', 400);
        }

        $jasa = $consultation->jasa;

        // Determine delivery type from jasa
        $deliveryType = $jasa->delivery_type ?? 'in-store';

        // Build validation rules based on service type
        $validationRules = [
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'booking_date' => 'nullable|date',
            'booking_time' => 'nullable|date_format:H:i:s',
            'booking_note' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:COD,MANUAL,cod,manual',
        ];

        // on-site requires customer address and coordinates
        if ($deliveryType === 'on-site') {
            $validationRules['customer_address'] = 'required|string|max:500';
            $validationRules['customer_latitude'] = 'nullable|numeric';
            $validationRules['customer_longitude'] = 'nullable|numeric';
        } else {
            // online and in-store don't need customer_address
            $validationRules['customer_address'] = 'nullable|string|max:500';
        }

        $data = $request->validate($validationRules);

        try {
            DB::beginTransaction();

            // Use customer profile data if not provided
            $customerName = $data['customer_name'] ?? $consultation->customer?->name ?? 'Pelanggan';
            $customerPhone = $data['customer_phone'] ?? $consultation->customer?->phone ?? null;

            // Determine address based on delivery type
            $customerAddress = null;
            $customerLatitude = null;
            $customerLongitude = null;

            if ($deliveryType === 'on-site') {
                // Customer provides their address (pekerja datang ke pelanggan)
                $customerAddress = $data['customer_address'] ?? null;
                $customerLatitude = $data['customer_latitude'] ?? null;
                $customerLongitude = $data['customer_longitude'] ?? null;
            }

            // ============================================================
            // VALIDASI DOUBLE BOOKING
            // Cek apakah sudah ada pesanan aktif pada tanggal & jam yang sama
            // ============================================================
            $bookingDate = !empty($data['booking_date']) ? $data['booking_date'] : ($consultation->proposed_date ?? null);
            $bookingTime = !empty($data['booking_time']) ? $data['booking_time'] : ($consultation->proposed_time ?? null);

            if ($bookingDate && $bookingTime) {
                $activeStatuses = [
                    'pending',
                    'accepted',
                    'on-progress',
                    'delivered',
                    'ready_to_pickup',
                ];

                $existingBooking = JasaOrderItem::where('jasa_id', $consultation->jasa_id)
                    ->where('booking_date', $bookingDate)
                    ->where('booking_time', $bookingTime)
                    ->whereIn('order_method', ['booking', 'konsultasi'])
                    ->whereHas('order', function ($query) use ($activeStatuses) {
                        $query->whereIn('status', $activeStatuses);
                    })
                    ->first();

                if ($existingBooking) {
                    return response()->json([
                        'message' => 'Jadwal sudah terisi, silakan pilih jam lain.',
                    ], 409);
                }
            }

            // Final price and booking data
            $finalPrice = $consultation->negotiated_price ?? $consultation->merchant_offered_price ?? $consultation->original_price;
            $bookingNote = $data['booking_note'] ?? $consultation->proposed_notes ?? null;
            $paymentMethod = strtoupper($data['payment_method'] ?? 'COD');
            // order_method: consultation (PRIMARY) - konsultasi flow always uses consultation

            // Get service image URL for snapshot
            $serviceImageSnapshot = null;
            if ($jasa->coverImage) {
                $serviceImageSnapshot = $jasa->coverImage->image_path;
            } elseif ($jasa->image) {
                $serviceImageSnapshot = $jasa->image;
            }

            // ===== 1. Create order in orders table (PRIMARY SOURCE) =====
            $platformFee = 0;
            $grossAmount = $finalPrice + $platformFee;
            $orderCode = 'JSA-' . strtoupper(substr(md5(uniqid()), 0, 10));

            $order = Order::create([
                'user_id' => $consultation->customer_id,
                'merchant_id' => $consultation->merchant_id,
                'order_type' => 'jasa',
                'order_code' => $orderCode,
                'subtotal' => $finalPrice,
                'discount_total' => 0,
                'platform_fee' => $platformFee,
                'gross_amount' => $grossAmount,
                'net_amount' => $finalPrice,
                'delivery_type' => $deliveryType,
                'delivery_fee_snapshot' => 0,
                'payment_method' => $paymentMethod,
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'confirm_deadline' => strtoupper($paymentMethod) === 'COD' ? now()->addMinutes(30) : null,
                'user_name_snapshot' => $customerName,
                'user_phone_snapshot' => $customerPhone,
                'address_detail_snapshot' => (string) ($customerAddress ?? ''),
                'province_name_snapshot' => '',
                'city_name_snapshot' => '',
                'district_name_snapshot' => '',
                'village_name_snapshot' => '',
                'latitude_snapshot' => $customerLatitude,
                'longitude_snapshot' => $customerLongitude,
                'notes' => $bookingNote,
            ]);

            // ===== 2. Create jasa_order_items =====
            $jasaOrderItem = JasaOrderItem::create([
                'order_id' => $order->id,
                'jasa_id' => $consultation->jasa_id,
                'service_consultation_id' => $consultation->id,
                'quantity' => 1,
                'price' => $finalPrice,
                'subtotal' => $finalPrice,
                'booking_date' => $bookingDate,
                'booking_time' => $bookingTime,
                'order_method' => 'konsultasi',
                'jasa_title_snapshot' => $jasa->title,
                'jasa_image_snapshot' => $serviceImageSnapshot,
                'jasa_price_snapshot' => $jasa->base_price ?? $jasa->price ?? $jasa->fixed_price,
            ]);

            // ===== 3. Link consultation to order =====
            // Relasi order konsultasi disimpan melalui jasa_order_items.service_consultation_id.
            // Tabel service_consultations tidak memiliki kolom order_id.
            $consultation->save();

            DB::commit();

            Log::info('[bookConsultation] Order created from consultation', [
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'consultation_id' => $consultation->id,
                'merchant_id' => $consultation->merchant_id,
                'customer_id' => $consultation->customer_id,
                'final_price' => $finalPrice,
                'snapshots_captured' => [
                    'jasa_title' => $jasa->title,
                    'customer_name' => $customerName,
                    'merchant_name' => $consultation->merchant?->name,
                ],
            ]);

            // Generate WhatsApp URL for booking notification
            $whatsappUrl = $this->generateBookingWhatsAppUrl($consultation);

            // Load relasi untuk response
            $order->load(['merchant', 'jasaItems.jasa']);

            return ApiResponse::success([
                'order_id' => $order->id,
                'jasa_order_item_id' => $jasaOrderItem->id,
                'status' => $order->status,
                'payment_method' => $paymentMethod,
                'whatsapp_url' => $whatsappUrl,
            ], 'Booking berhasil diajukan! Menunggu konfirmasi dari merchant.', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Consultation Book] Error', ['error' => $e->getMessage(), 'consultation_id' => $id]);
            return ApiResponse::error('Gagal membuat booking: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Close consultation without agreement
     * Used by both customer and merchant routes
     */
    public function closeConsultation(Request $request)
    {
        // Get consultation ID - find the numeric parameter (works for both routes)
        $params = $request->route()?->parameters() ?? [];
        $consultationId = null;
        foreach ($params as $key => $value) {
            if (is_numeric($value) && $key !== 'merchant') {
                $consultationId = $value;
                break;
            }
        }

        Log::info('[CloseConsultation] Request', [
            'consultation_id' => $consultationId,
            'route' => $request->route()?->uri(),
            'params' => $params,
            'auth_id' => Auth::id(),
        ]);

        if (!$consultationId) {
            Log::warning('[CloseConsultation] No consultation ID found', ['params' => $params]);
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        $consultation = ServiceConsultation::find((int) $consultationId);

        if (!$consultation) {
            Log::warning('[CloseConsultation] Not found', ['id' => $consultationId]);
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        // Check access - merchant can close if merchant_id matches merchant's user
        $userId = Auth::id();

        // Check if user is the merchant owner
        $merchant = Merchant::find($consultation->merchant_id);
        $isMerchantOwner = $merchant && $merchant->user_id === $userId;
        $isCustomer = $consultation->customer_id === $userId;

        Log::info('[CloseConsultation] Access check', [
            'user_id' => $userId,
            'is_merchant_owner' => $isMerchantOwner,
            'is_customer' => $isCustomer,
            'customer_id' => $consultation->customer_id,
            'merchant_user_id' => $merchant?->user_id,
        ]);

        if (!$isMerchantOwner && !$isCustomer) {
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        // Check if already closed
        // Jika sudah closed, jangan error.
        // Anggap berhasil agar frontend tidak stuck di popup.
        if ($consultation->status === ServiceConsultation::STATUS_CLOSED) {
            return ApiResponse::success(
                $this->attachConsultationOrderSummary(
                    $consultation->fresh(['messages.media', 'jasaOrderItems.order.payment'])
                ),
                'Konsultasi sudah ditutup sebelumnya'
            );
        }

        $consultation->loadMissing(['jasaOrderItems.order.payment']);
        $order = $consultation->jasaOrderItems->first()?->order;

        if ($order && $this->isOrderPaid($order)) {
            return ApiResponse::error('Pembayaran sudah berhasil, percakapan tidak dapat dihentikan.', 400);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($consultation, $order, $data) {
            if ($order && !in_array($order->status, ['cancelled', 'rejected', 'completed'], true)) {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

                if ($order->payment && in_array(strtoupper((string) $order->payment->status), ['PENDING', 'UNPAID'], true)) {
                    $order->payment->update(['status' => 'EXPIRED']);
                }
            }

            $consultation->close($data['reason'] ?? 'Percakapan dihentikan.');

            ConsultationMessage::create([
                'service_consultation_id' => $consultation->id,
                'sender_id' => Auth::id(),
                'sender_type' => 'system',
                'message' => 'Percakapan dihentikan.',
                'message_type' => 'conversation_closed',
            ]);
        });

        return ApiResponse::success($this->attachConsultationOrderSummary($consultation->fresh(['messages.media', 'jasaOrderItems.order.payment'])), 'Percakapan berhasil dihentikan');
    }
}