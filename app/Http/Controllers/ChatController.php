<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Jasa;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    // List conversations for current user (buyer or merchant)
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Conversation::with(['buyer', 'merchant', 'jasa'])
            ->orderByDesc('last_message_at')->orderByDesc('id');

        // If user is merchant owner, show conversations for their merchants
        if ($user->roles && $user->roles->contains('name', 'umkm-owner')) {
            $merchantIds = Merchant::where('user_id', $user->id)->pluck('id');
            $query->whereIn('merchant_id', $merchantIds);
        } else {
            // Assume as buyer (customer)
            $query->where('buyer_id', $user->id);
        }

        $conversations = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $conversations->items(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    // Start or get existing conversation for a jasa (buyer side)
    public function start(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'jasa_id' => 'required|exists:jasas,id',
        ]);

        $jasa = Jasa::with('merchant')->findOrFail($data['jasa_id']);
        $merchantId = $jasa->merchant_id;

        $conversation = Conversation::firstOrCreate(
            [
                'buyer_id' => $user->id,
                'merchant_id' => $merchantId,
                'jasa_id' => $jasa->id,
            ],
            [
                'status' => 'open',
                'last_message_at' => now(),
            ]
        );

        return response()->json([
            'data' => $conversation->load(['buyer', 'merchant', 'jasa']),
        ], 201);
    }

    // Get conversation + messages
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $conversation = Conversation::with(['buyer', 'merchant', 'jasa'])
            ->findOrFail($id);

        // Simple authorization: must be buyer or merchant in this conversation
        if ($conversation->buyer_id !== $user->id &&
            !$this->userIsMerchantInConversation($user, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => [
                'conversation' => $conversation,
                'messages' => $messages,
            ],
        ]);
    }

    // Send plain text message
    public function sendMessage(Request $request, $id)
    {
        $user = $request->user();

        $conversation = Conversation::findOrFail($id);

        if ($conversation->buyer_id !== $user->id &&
            !$this->userIsMerchantInConversation($user, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'body' => 'required|string',
        ]);

        $senderRole = $this->detectSenderRole($user, $conversation);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_role' => $senderRole,
            'type' => 'message',
            'body' => $data['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json(['data' => $message], 201);
    }

    // Merchant makes an offer in the chat
    public function makeOffer(Request $request, $id)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if (!$this->userIsMerchantInConversation($user, $conversation)) {
            return response()->json(['message' => 'Hanya merchant yang dapat membuat penawaran'], 403);
        }

        $data = $request->validate([
            'offer_price' => 'required|integer|min:0',
            'body' => 'nullable|string',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'sender_role' => 'merchant',
            'type' => 'offer',
            'body' => $data['body'] ?? null,
            'jasa_id' => $conversation->jasa_id,
            'offer_price' => $data['offer_price'],
            'offer_status' => 'pending',
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json(['data' => $message], 201);
    }

    // Buyer accepts an offer
    public function acceptOffer(Request $request, $id, $messageId)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if ($conversation->buyer_id !== $user->id) {
            return response()->json(['message' => 'Hanya pembeli yang dapat menerima penawaran'], 403);
        }

        $message = Message::where('conversation_id', $conversation->id)
            ->where('id', $messageId)
            ->where('type', 'offer')
            ->firstOrFail();

        $message->offer_status = 'accepted';
        $message->save();

        // Optionally: create Order here from jasa & offer_price

        return response()->json(['data' => $message]);
    }

    // Buyer rejects an offer
    public function rejectOffer(Request $request, $id, $messageId)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        if ($conversation->buyer_id !== $user->id) {
            return response()->json(['message' => 'Hanya pembeli yang dapat menolak penawaran'], 403);
        }

        $message = Message::where('conversation_id', $conversation->id)
            ->where('id', $messageId)
            ->where('type', 'offer')
            ->firstOrFail();

        $message->offer_status = 'rejected';
        $message->save();

        return response()->json(['data' => $message]);
    }

    protected function userIsMerchantInConversation($user, Conversation $conversation): bool
    {
        // User is merchant when they own the merchant in this conversation
        $merchant = Merchant::where('id', $conversation->merchant_id)
            ->where('user_id', $user->id)
            ->first();

        return (bool) $merchant;
    }

    protected function detectSenderRole($user, Conversation $conversation): string
    {
        if ($conversation->buyer_id === $user->id) {
            return 'buyer';
        }

        if ($this->userIsMerchantInConversation($user, $conversation)) {
            return 'merchant';
        }

        return 'customer';
    }
}
