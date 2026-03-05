<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Jasa;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    private function isMerchantConversationOwner(Conversation $conversation, $user): bool
    {
        if (!$user)
            return false;
        return Merchant::where('id', $conversation->merchant_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function canAccessConversation(Conversation $conversation, $user): bool
    {
        if (!$user)
            return false;
        return $conversation->buyer_id === $user->id
            || $this->isMerchantConversationOwner($conversation, $user)
            || $user->hasRole('admin');
    }

    /**
     * GET /api/chats
     * Dapatkan daftar chat untuk merchant yang login
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Validate merchant access
        $merchant = Merchant::where('user_id', $user->id)->firstOrFail();

        $query = Conversation::where('merchant_id', $merchant->id)
            ->with([
                'buyer' => fn($q) => $q->select('id', 'name', 'profile_picture_path'),
                'jasa' => fn($q) => $q->select('id', 'title', 'slug'),
                'lastMessage' => fn($q) => $q->select('id', 'conversation_id', 'body', 'sender_role', 'created_at'),
            ])
            ->orderBy('updated_at', 'desc');

        // Filter by search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('buyer', fn($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('jasa', fn($q) => $q->where('title', 'like', "%{$search}%"));
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $conversations = $query->paginate(15);

        // Transform response to match frontend expectations
        return response()->json([
            'data' => $conversations->map(function ($conversation) {
                $lastMsg = $conversation->lastMessage;
                return [
                    'id' => $conversation->id,
                    'buyer_id' => $conversation->buyer_id,
                    'merchant_id' => $conversation->merchant_id,
                    'jasa_id' => $conversation->jasa_id,
                    'status' => $conversation->status,
                    'user' => $conversation->buyer ? [
                        'id' => $conversation->buyer->id,
                        'name' => $conversation->buyer->name,
                        'profile_picture' => $conversation->buyer->profile_picture_path ? url("/api/profile-pictures/{$conversation->buyer->id}") : null,
                    ] : null,
                    'jasa' => $conversation->jasa ? [
                        'id' => $conversation->jasa->id,
                        'title' => $conversation->jasa->title,
                        'slug' => $conversation->jasa->slug,
                    ] : null,
                    'last_message' => $lastMsg ? [
                        'id' => $lastMsg->id,
                        'body' => $lastMsg->body,
                        'sender_role' => $lastMsg->sender_role,
                        'created_at' => $lastMsg->created_at,
                    ] : null,
                    'unread_count' => 0,
                    'created_at' => $conversation->created_at,
                    'updated_at' => $conversation->updated_at,
                ];
            }),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'total' => $conversations->total(),
                'per_page' => $conversations->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/chats/start
     * Mulai percakapan baru sebagai buyer
     */
    public function start(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'jasa_id' => 'required|exists:jasas,id',
        ]);

        // Get the jasa to find the merchant
        $jasa = Jasa::findOrFail($data['jasa_id']);
        $merchantId = $jasa->merchant_id;

        // Check if conversation already exists
        $conversation = Conversation::where([
            'buyer_id' => $user->id,
            'merchant_id' => $merchantId,
            'jasa_id' => $jasa->id,
        ])->first();

        // Create new conversation if doesn't exist
        if (!$conversation) {
            $conversation = Conversation::create([
                'buyer_id' => $user->id,
                'merchant_id' => $merchantId,
                'jasa_id' => $jasa->id,
                'status' => 'active',
            ]);
        }

        // Load related data
        $conversation->load([
            'buyer' => fn($q) => $q->select('id', 'name', 'email', 'phone', 'profile_picture_path'),
            'jasa' => fn($q) => $q->select('id', 'title', 'slug'),
            'messages' => fn($q) => $q->with('sender:id,name,profile_picture_path')
                ->select('id', 'conversation_id', 'sender_id', 'sender_role', 'body', 'type', 'offer_price', 'offer_status', 'created_at')
                ->whereIn('type', ['text', 'offer'])
                ->orderBy('created_at', 'asc'),
        ]);

        return response()->json([
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'buyer_id' => $conversation->buyer_id,
                    'merchant_id' => $conversation->merchant_id,
                    'jasa_id' => $conversation->jasa_id,
                    'status' => $conversation->status,
                    'buyer' => $conversation->buyer ? [
                        'id' => $conversation->buyer->id,
                        'name' => $conversation->buyer->name,
                        'email' => $conversation->buyer->email,
                        'phone' => $conversation->buyer->phone,
                        'profile_picture' => $conversation->buyer->profile_picture_path ? url("/api/profile-pictures/{$conversation->buyer->id}") : null,
                    ] : null,
                    'jasa' => $conversation->jasa ? [
                        'id' => $conversation->jasa->id,
                        'title' => $conversation->jasa->title,
                        'slug' => $conversation->jasa->slug,
                    ] : null,
                    'created_at' => $conversation->created_at,
                    'updated_at' => $conversation->updated_at,
                ],
                'messages' => $conversation->messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'sender_id' => $message->sender_id,
                        'sender_role' => $message->sender_role,
                        'body' => $message->body,
                        'is_read' => false,
                        'type' => $message->type,
                        'created_at' => $message->created_at,
                        'sender' => $message->sender ? [
                            'id' => $message->sender->id,
                            'name' => $message->sender->name,
                            'profile_picture' => $message->sender->profile_picture_path ? url("/api/profile-pictures/{$message->sender->id}") : null,
                        ] : null,
                    ];
                }),
            ],
        ]);
    }

    /**
     * GET /api/chats/{id}
     * Dapatkan detail chat dengan semua messages
     */
    public function show(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate access (merchant, buyer, or admin)
        $isAuthorized = $this->canAccessConversation($conversation, $user);

        if (!$isAuthorized) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Mark messages as read (no-op on current schema)
        $conversation->markAsRead();

        $conversation->load([
            'buyer' => fn($q) => $q->select('id', 'name', 'email', 'phone', 'profile_picture_path'),
            'jasa' => fn($q) => $q->select('id', 'title', 'slug'),
            'messages' => fn($q) => $q->with('sender:id,name,profile_picture_path')
                ->select('id', 'conversation_id', 'sender_id', 'sender_role', 'body', 'type', 'offer_price', 'offer_status', 'created_at')
                ->whereIn('type', ['text', 'offer'])
                ->orderBy('created_at', 'asc'),
        ]);

        return response()->json([
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'buyer_id' => $conversation->buyer_id,
                    'merchant_id' => $conversation->merchant_id,
                    'jasa_id' => $conversation->jasa_id,
                    'status' => $conversation->status,
                    'user' => $conversation->buyer ? [
                        'id' => $conversation->buyer->id,
                        'name' => $conversation->buyer->name,
                        'email' => $conversation->buyer->email,
                        'phone' => $conversation->buyer->phone,
                        'profile_picture' => $conversation->buyer->profile_picture_path ? url("/api/profile-pictures/{$conversation->buyer->id}") : null,
                    ] : null,
                    'jasa' => $conversation->jasa ? [
                        'id' => $conversation->jasa->id,
                        'title' => $conversation->jasa->title,
                        'slug' => $conversation->jasa->slug,
                    ] : null,
                    'created_at' => $conversation->created_at,
                    'updated_at' => $conversation->updated_at,
                ],
                'messages' => $conversation->messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'sender_id' => $message->sender_id,
                        'sender_role' => $message->sender_role,
                        'body' => $message->body,
                        'is_read' => false,
                        'type' => $message->type,
                        'offer_price' => $message->offer_price,
                        'offer_status' => $message->offer_status,
                        'created_at' => $message->created_at,
                        'sender' => $message->sender ? [
                            'id' => $message->sender->id,
                            'name' => $message->sender->name,
                            'profile_picture' => $message->sender->profile_picture_path ? url("/api/profile-pictures/{$message->sender->id}") : null,
                        ] : null,
                    ];
                }),
            ],
        ]);
    }

    /**
     * POST /api/chats/{id}/messages
     * Kirim pesan baru ke chat
     */
    public function sendMessage(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate merchant access
        if (!$this->isMerchantConversationOwner($conversation, $user) && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'sender_role' => 'merchant',
            'type' => 'text',
            'body' => $data['body'],
        ]);

        // Update conversation's updated_at
        $conversation->touch();

        return response()->json([
            'message' => 'Pesan berhasil dikirim',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'sender_role' => $message->sender_role,
                'body' => $message->body,
                'is_read' => false,
                'type' => $message->type,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    /**
     * POST /api/chats/{id}/buyer-messages
     * Kirim pesan baru ke chat sebagai buyer
     */
    public function sendBuyerMessage(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate buyer access
        if ($conversation->buyer_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'sender_role' => 'buyer',
            'type' => 'text',
            'body' => $data['body'],
        ]);

        // Update conversation's updated_at
        $conversation->touch();

        return response()->json([
            'message' => 'Pesan berhasil dikirim',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'sender_role' => $message->sender_role,
                'body' => $message->body,
                'is_read' => false,
                'type' => $message->type,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    /**
     * POST /api/chats/{id}/offer
     * Buat penawaran harga untuk chat
     */
    public function makeOffer(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate merchant access
        if (!$this->isMerchantConversationOwner($conversation, $user) && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'price' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:1000',
        ]);

        // Update conversation status
        $conversation->update([
            'status' => 'pending_offer',
        ]);

        // Create message with offer details
        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'sender_role' => 'merchant',
            'type' => 'offer',
            'body' => "💰 Penawaran harga: Rp " . number_format($data['price'], 0, ',', '.') .
                ($data['note'] ? "\n\n📝 " . $data['note'] : ""),
            'offer_price' => (int) $data['price'],
            'offer_status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Penawaran berhasil dikirim',
            'data' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'offer_price' => (int) $data['price'],
                'offer_note' => $data['note'] ?? null,
            ],
        ], 201);
    }

    /**
     * PUT /api/chats/{id}/offer/accept
     * Terima penawaran dari pembeli
     */
    public function acceptOffer(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate merchant access
        if ($conversation->merchant_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($conversation->status !== 'pending_offer') {
            return response()->json([
                'message' => 'Chat tidak memiliki penawaran yang pending',
            ], 422);
        }

        // Update conversation status
        $conversation->update([
            'status' => 'deal_accepted',
        ]);

        // Send system message
        $conversation->messages()->create([
            'sender_id' => $user->id,
            'sender_role' => 'merchant',
            'type' => 'text',
            'body' => '✅ Penawaran diterima!',
        ]);

        return response()->json([
            'message' => 'Penawaran berhasil diterima',
            'data' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
            ],
        ]);
    }

    /**
     * PUT /api/chats/{id}/status
     * Update status chat
     */
    public function updateStatus(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate merchant access
        if (!$this->isMerchantConversationOwner($conversation, $user) && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:active,pending_offer,deal_accepted,completed,cancelled',
        ]);

        $oldStatus = $conversation->status;
        $conversation->update(['status' => $data['status']]);

        // Send system message
        $statusLabels = [
            'active' => 'kembali aktif',
            'pending_offer' => 'pending penawaran',
            'deal_accepted' => 'deal diterima',
            'completed' => 'selesai',
            'cancelled' => 'dibatalkan',
        ];

        $conversation->messages()->create([
            'sender_id' => $user->id,
            'sender_role' => 'merchant',
            'type' => 'text',
            'body' => "📌 Status pembahasan berubah menjadi: " . ($statusLabels[$data['status']] ?? $data['status']),
        ]);

        return response()->json([
            'message' => 'Status berhasil diupdate',
            'data' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
            ],
        ]);
    }

    /**
     * DELETE /api/chats/{id}
     * Hapus/arsipkan chat
     */
    public function destroy(Request $request, Conversation $conversation)
    {
        $user = $request->user();

        // Validate merchant access
        if (!$this->isMerchantConversationOwner($conversation, $user) && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $conversation->delete();

        return response()->json([
            'message' => 'Chat berhasil dihapus',
        ]);
    }
}
