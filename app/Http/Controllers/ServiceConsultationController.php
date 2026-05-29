<?php

namespace App\Http\Controllers;

use App\Models\ServiceConsultation;
use App\Models\ServiceConsultationMedia;
use App\Models\ServiceConsultationNote;
use App\Models\ServiceOrder;
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
     */
    protected function getStatusGroupStatuses(string $group): array
    {
        return match ($group) {
            'menunggu' => [ServiceConsultation::STATUS_PENDING],
            'negosiasi' => [ServiceConsultation::STATUS_DAPAT_DIKERJAKAN, ServiceConsultation::STATUS_PENYESUAIAN],
            'selesai' => [ServiceConsultation::STATUS_ACCEPTED, ServiceConsultation::STATUS_DITOLAK, ServiceConsultation::STATUS_CLOSED],
            default => [],
        };
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

            // Handle media uploads
            $mediaFiles = $request->file('media') ?? [];
            foreach ($mediaFiles as $index => $file) {
                $type = str_starts_with($file->getMimeType(), 'image/') ? 'images' : 'videos';
                $path = ServiceConsultationMedia::generatePath($file->getClientOriginalName(), $type);

                Storage::disk('public')->put($path, file_get_contents($file));

                ServiceConsultationMedia::create([
                    'service_consultation_id' => $consultation->id,
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

            $consultation->load(['media', 'jasa']);

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
                'jasa:id,title,price,base_price,fixed_price,image',
                'merchant:id,name,slug',
                'media',
                'notes',
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
                'serviceOrder',
            ])->find($id);

            if (!$consultation) {
                return ApiResponse::error('Konsultasi tidak ditemukan', 404);
            }

            // Customer can only see their own consultations
            if ($consultation->customer_id !== Auth::id()) {
                return ApiResponse::error('Tidak memiliki akses', 403);
            }

            return ApiResponse::success($consultation, 'success');
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

        if (!$consultation->isActive()) {
            return ApiResponse::error('Konsultasi sudah tidak aktif', 400);
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

        $status = $request->get('status');
        $statusGroup = $request->get('status_group');
        $perPage = $request->get('per_page', 10);

        $query = ServiceConsultation::with([
                'jasa:id,title,price,base_price,fixed_price,image',
                'customer:id,name,phone',
                'media',
                'notes',
            ])
            ->forMerchant($merchant->id)
            ->orderByDesc('created_at');

        // Filter by status_group (menunggu, negosiasi, selesai)
        if ($statusGroup) {
            $statuses = $this->getStatusGroupStatuses($statusGroup);
            $query->whereIn('status', $statuses);
        } elseif ($status) {
            $query->where('status', $status);
        }

        $consultations = $query->paginate($perPage);

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
                'serviceOrder',
            ])
                ->where('merchant_id', $merchant->id)
                ->where('id', $id)
                ->first();

            if (!$consultation) {
                return ApiResponse::error('Konsultasi tidak ditemukan', 404);
            }

            return ApiResponse::success($consultation, 'success');
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

        // Validate request data
        $data = $request->validate([
            'response' => 'required|in:bisa_dikerjakan,perlu_penyesuaian,tidak_bisa_dikerjakan',
            'merchant_offered_price' => 'nullable|numeric|min:0',
            'merchant_note' => 'nullable|string|max:1000',
        ]);

        // Find consultation belonging to this merchant
        $consultation = ServiceConsultation::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        if (!$consultation) {
            Log::warning('[respond] Consultation not found', ['id' => $id, 'merchant_id' => $merchant->id]);
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
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

        return ApiResponse::success($consultation->fresh(['media', 'notes', 'messages']), 'Tanggapan berhasil dikirim');
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

        if (!$consultation->isActive()) {
            return ApiResponse::error('Konsultasi sudah tidak aktif', 400);
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

        try {
            DB::beginTransaction();

            // Create service order
            $serviceOrder = ServiceOrder::create([
                'customer_id' => $consultation->customer_id,
                'merchant_id' => $consultation->merchant_id,
                'jasa_id' => $consultation->jasa_id,
                'consultation_id' => $consultation->id,
                'service_name' => $consultation->service_name,
                'service_image' => $jasa->image ? asset('storage/' . $jasa->image) : null,
                'merchant_name' => $jasa->merchant?->name ?? 'UMKM',
                'total_price' => $consultation->negotiated_price ?? $consultation->merchant_offered_price ?? $consultation->original_price,
                'status' => ServiceOrder::STATUS_MENUNGGU_KONFIRMASI,
                // Use proposed date/time from consultation
                'booking_date' => $consultation->proposed_date,
                'booking_time' => $consultation->proposed_time,
                'booking_note' => $consultation->proposed_notes ?? $consultation->negotiation_notes,
                'customer_name' => $consultation->customer?->name ?? 'Pelanggan',
                'customer_phone' => $consultation->customer?->phone ?? null,
                'payment_method' => 'COD',
                'payment_status' => ServiceOrder::PAYMENT_UNPAID,
            ]);

            // Update consultation status to accepted
            $consultation->status = ServiceConsultation::STATUS_ACCEPTED;
            $consultation->service_order_id = $serviceOrder->id;
            $consultation->save();

            DB::commit();

            $serviceOrder->load(['merchant', 'jasa']);

            return ApiResponse::success($serviceOrder, 'Pesanan berhasil dibuat. Menunggu konfirmasi Anda.', 201);
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
    public function acceptOffer(Request $request, int $id)
    {
        Log::info('[AcceptOffer] Request received', [
            'consultation_id' => $id,
            'user_id' => Auth::id(),
        ]);

        $data = $request->validate([
            'proposed_date' => 'nullable|date',
            'proposed_time' => 'nullable|date_format:H:i',
            'proposed_notes' => 'nullable|string|max:1000',
        ]);

        $consultation = ServiceConsultation::with(['jasa', 'merchant'])->find($id);

        if (!$consultation) {
            Log::warning('[AcceptOffer] Consultation not found', ['id' => $id]);
            return ApiResponse::error('Konsultasi tidak ditemukan', 404);
        }

        if ($consultation->customer_id !== Auth::id()) {
            Log::warning('[AcceptOffer] Access denied', [
                'consultation_id' => $id,
                'owner_id' => $consultation->customer_id,
                'requester_id' => Auth::id(),
            ]);
            return ApiResponse::error('Tidak memiliki akses', 403);
        }

        // Check if merchant has responded with an offer
        if (!$consultation->merchant_response) {
            Log::warning('[AcceptOffer] No offer from merchant', [
                'consultation_id' => $id,
                'merchant_response' => $consultation->merchant_response,
            ]);
            return ApiResponse::error('Belum ada penawaran dari merchant', 400);
        }

        // Check if merchant rejected the offer
        if ($consultation->merchant_response === 'tidak_bisa_dikerjakan') {
            Log::warning('[AcceptOffer] Merchant rejected', ['consultation_id' => $id]);
            return ApiResponse::error('Merchant tidak dapat mengerjakan layanan ini', 400);
        }

        // Check if already accepted FIRST - return 200 with data (not error)
        if ($consultation->customer_accepted || $consultation->status === ServiceConsultation::STATUS_ACCEPTED) {
            Log::info('[AcceptOffer] Already accepted - returning current state', ['consultation_id' => $id]);
            return ApiResponse::success([
                'consultation' => $consultation->fresh(['jasa', 'merchant', 'customer', 'messages']),
                'booking_card' => [
                    'service_name' => $consultation->service_name,
                    'merchant_name' => $consultation->merchant?->name ?? 'UMKM',
                    'final_price' => $consultation->negotiated_price,
                    'has_service_order' => $consultation->service_order_id !== null,
                ],
            ], 'Penawaran sudah diterima sebelumnya.', 200);
        }

        // Statuses that can be accepted
        $canAcceptStatuses = [
            ServiceConsultation::STATUS_PENDING,
            ServiceConsultation::STATUS_DAPAT_DIKERJAKAN,
            ServiceConsultation::STATUS_PENYESUAIAN,
        ];

        if (!in_array($consultation->status, $canAcceptStatuses)) {
            Log::warning('[AcceptOffer] Invalid status for accept', [
                'consultation_id' => $id,
                'current_status' => $consultation->status,
                'allowed_statuses' => $canAcceptStatuses,
            ]);
            return ApiResponse::error(
                'Konsultasi tidak dapat diproses. Status saat ini: ' . $consultation->status,
                400
            );
        }

        // Validate that there's a price to accept
        if (!$consultation->merchant_offered_price) {
            Log::warning('[AcceptOffer] No price offered', ['consultation_id' => $id]);
            return ApiResponse::error('Tidak ada harga yang ditawarkan merchant', 400);
        }

        // Accept the offer and save booking proposal
        $consultation->customer_accepted = true;
        $consultation->customer_accepted_at = now();
        $consultation->negotiated_price = $consultation->merchant_offered_price;
        $consultation->status = ServiceConsultation::STATUS_ACCEPTED;

        // Save booking proposal data if provided
        if (!empty($data['proposed_date'])) {
            $consultation->proposed_date = $data['proposed_date'];
        }
        if (!empty($data['proposed_time'])) {
            $consultation->proposed_time = $data['proposed_time'] . ':00';
        }
        if (!empty($data['proposed_notes'])) {
            $consultation->proposed_notes = $data['proposed_notes'];
        }

        $consultation->save();

        Log::info('[AcceptOffer] Successfully accepted', [
            'consultation_id' => $id,
            'final_price' => $consultation->negotiated_price,
            'proposed_date' => $consultation->proposed_date,
            'proposed_time' => $consultation->proposed_time,
        ]);

        // Create system message for acceptance
        $formattedPrice = 'Rp ' . number_format($consultation->negotiated_price, 0, ',', '.');
        $acceptanceMessage = ConsultationMessage::create([
            'service_consultation_id' => $consultation->id,
            'sender_id' => Auth::id(),
            'sender_type' => 'customer',
            'message' => "Pelanggan menerima penawaran harga {$formattedPrice}. Harga kesepakatan telah ditetapkan.",
            'proposed_price' => $consultation->negotiated_price,
        ]);

        return ApiResponse::success([
            'consultation' => $consultation->fresh(['jasa', 'merchant', 'customer', 'messages']),
            'booking_card' => [
                'service_name' => $consultation->service_name,
                'merchant_name' => $consultation->merchant?->name ?? 'UMKM',
                'final_price' => $consultation->negotiated_price,
                'has_service_order' => $consultation->service_order_id !== null,
            ],
        ], 'Penawaran diterima! Silakan klik Booking Sekarang untuk melanjutkan.', 200);
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
     * Creates service_order and returns WhatsApp URL for payment notification.
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

        // Prevent double booking - return existing order instead of error
        if ($consultation->service_order_id) {
            $existingOrder = ServiceOrder::with(['merchant', 'jasa'])
                ->where('id', $consultation->service_order_id)
                ->first();
            if ($existingOrder) {
                Log::info('[bookConsultation] Order already exists - returning existing order', [
                    'consultation_id' => $id,
                    'order_id' => $existingOrder->id,
                ]);
                return ApiResponse::success([
                    'service_order' => $existingOrder,
                    'whatsapp_url' => null,
                    'already_exists' => true,
                ], 'Booking sudah pernah dibuat sebelumnya.', 200);
            }
        }

        // Validate final price exists
        if (!$consultation->negotiated_price && !$consultation->merchant_offered_price) {
            return ApiResponse::error('Harga kesepakatan belum ada', 400);
        }

        $jasa = $consultation->jasa;

        // Determine service type and validate address accordingly
        $serviceType = strtolower(
            $jasa->service_type ??
            $jasa->service?->service_type ??
            ''
        );

        // Build validation rules based on service type
        $validationRules = [
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'booking_date' => 'nullable|date',
            'booking_time' => 'nullable|date_format:H:i:s',
            'booking_note' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:COD,MANUAL,cod,manual',
        ];

        // ke_rumah_pelanggan requires customer address and coordinates
        if ($serviceType === 'ke_rumah_pelanggan') {
            $validationRules['customer_address'] = 'required|string|max:500';
            $validationRules['customer_latitude'] = 'nullable|numeric';
            $validationRules['customer_longitude'] = 'nullable|numeric';
        } else {
            // online and di_tempat_umkm don't need customer_address
            $validationRules['customer_address'] = 'nullable|string|max:500';
        }

        $data = $request->validate($validationRules);

        try {
            DB::beginTransaction();

            // Use customer profile data if not provided
            $customerName = $data['customer_name'] ?? $consultation->customer?->name ?? 'Pelanggan';
            $customerPhone = $data['customer_phone'] ?? $consultation->customer?->phone ?? null;

            // Determine address based on service type
            $customerAddress = null;
            $customerLatitude = null;
            $customerLongitude = null;
            $serviceLocationAddress = null;

            if ($serviceType === 'ke_rumah_pelanggan') {
                // Customer provides their address
                $customerAddress = $data['customer_address'];
                $customerLatitude = $data['customer_latitude'] ?? null;
                $customerLongitude = $data['customer_longitude'] ?? null;
                $serviceLocationAddress = $data['customer_address']; // Customer address as service location
            } elseif ($serviceType === 'di_tempat_umkm') {
                // Service at merchant location
                $customerAddress = null;
                $serviceLocationAddress = $consultation->merchant?->address ??
                    $consultation->merchant?->alamat ??
                    $consultation->merchant?->full_address ??
                    null;
            } else {
                // Online service - mark as "Online"
                $customerAddress = null;
                $serviceLocationAddress = 'Online';
            }

            // Create service order
            $serviceOrder = ServiceOrder::create([
                'customer_id' => $consultation->customer_id,
                'merchant_id' => $consultation->merchant_id,
                'jasa_id' => $consultation->jasa_id,
                'consultation_id' => $consultation->id,
                'service_name' => $consultation->service_name,
                'service_type' => $serviceType,
                'service_image' => $jasa->cover_img?->url ?? null,
                'merchant_name' => $consultation->merchant?->name ?? 'UMKM',
                'total_price' => $consultation->negotiated_price ?? $consultation->merchant_offered_price ?? $consultation->original_price,
                'status' => ServiceOrder::STATUS_MENUNGGU_KONFIRMASI,
                // Use proposed date/time from consultation if available, otherwise from request
                'booking_date' => !empty($data['booking_date']) ? $data['booking_date'] : ($consultation->proposed_date ?? null),
                'booking_time' => !empty($data['booking_time']) ? $data['booking_time'] : ($consultation->proposed_time ?? null),
                'booking_note' => $data['booking_note'] ?? $consultation->proposed_notes ?? null,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $customerAddress,
                'customer_latitude' => $customerLatitude,
                'customer_longitude' => $customerLongitude,
                'service_location_address' => $serviceLocationAddress,
                'payment_method' => strtoupper($data['payment_method'] ?? 'COD'),
                'payment_status' => ServiceOrder::PAYMENT_UNPAID,
            ]);

            // Link service_order to consultation
            $consultation->service_order_id = $serviceOrder->id;
            $consultation->save();

            DB::commit();

            // Generate WhatsApp URL for booking notification
            $whatsappUrl = $this->generateBookingWhatsAppUrl($consultation);

            $serviceOrder->load(['merchant', 'jasa']);

            return ApiResponse::success([
                'service_order' => $serviceOrder,
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
        if ($consultation->status === ServiceConsultation::STATUS_CLOSED) {
            return ApiResponse::error('Konsultasi sudah ditutup sebelumnya', 400);
        }

        // Check if accepted
        if ($consultation->status === ServiceConsultation::STATUS_ACCEPTED) {
            return ApiResponse::error('Konsultasi sudah disepakati, tidak dapat ditutup', 400);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $ok = $consultation->close($data['reason'] ?? null);

        if (!$ok) {
            return ApiResponse::error('Konsultasi tidak dapat ditutup', 400);
        }

        return ApiResponse::success($consultation->fresh(), 'Konsultasi berhasil ditutup');
    }
}
