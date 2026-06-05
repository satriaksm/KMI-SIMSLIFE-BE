# Chat System Implementation - SUMILIR Platform

## Overview
Complete chat/messaging system for buyer-merchant conversations on jasa (service) listings, built for SUMILIR UMKM marketplace.

## Components Implemented

### Backend (Laravel)

#### 1. Models
- **`App\Models\Conversation`** - Represents a conversation between buyer and merchant
  - Fields: `buyer_id`, `merchant_id`, `jasa_id`, `status`, `last_message_at`
  - Relationships: buyer (User), merchant (User), jasa (Jasa), messages (Message)
  - Status values: `active`, `pending_offer`, `deal_accepted`, `completed`, `cancelled`
  - Methods: `markAsRead()`, scopes for filtering

- **`App\Models\Message`** - Individual messages within conversations
  - Fields: `conversation_id`, `sender_id`, `sender_role`, `type`, `body`, `offer_price`, `offer_status`, `is_read`, `read_at`
  - Sender roles: `buyer`, `customer`, `merchant`
  - Message types: `message`, `offer`, `system`
  - Relationships: conversation (Conversation), sender (User)

#### 2. API Controller
**`App\Http\Controllers\ChatController`** - RESTful API endpoints

**Endpoints:**

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/api/chats` | `chats.index` | List merchant's conversations (paginated) |
| GET | `/api/chats/{conversation}` | `chats.show` | Get single conversation with messages |
| POST | `/api/chats/{conversation}/messages` | `chats.messages.store` | Send message to conversation |
| POST | `/api/chats/{conversation}/offer` | `chats.offer.store` | Submit price offer |
| PUT | `/api/chats/{conversation}/offer/accept` | `chats.offer.accept` | Accept buyer's offer |
| PUT | `/api/chats/{conversation}/status` | `chats.status.update` | Update conversation status |
| DELETE | `/api/chats/{conversation}` | `chats.destroy` | Delete/archive conversation |

#### 3. API Features
- **Authentication**: All endpoints require `auth` + `verified` middleware
- **Authorization**: Merchant can only access their own conversations
- **Filtering**: 
  - Search by buyer name, jasa title
  - Filter by status
  - Pagination with 15 items per page
- **Relationships**: Eager loads buyer, jasa, messages, sender info
- **Message Reading**: Automatically marks buyer messages as read
- **Offer System**: Track price offers with status tracking

### Frontend (Vue 3)

#### 1. Composable
**`src/composables/useChat.js`** - State management for chat operations
- Methods:
  - `fetchConversations(params)` - Load conversations list
  - `loadConversation(id)` - Load single conversation with messages
  - `sendMessage(id, body)` - Send text message
  - `makeOffer(id, price, note)` - Submit price offer
  - `acceptOffer(id)` - Accept offer
  - `updateConversationStatus(id, status)` - Update status
  - `deleteConversation(id)` - Delete/archive

#### 2. Components
**`src/views/merchant/chats/Index.vue`** - Conversation list page
- Features:
  - Search by buyer name or jasa title
  - Filter by status (active, pending_offer, deal_accepted, completed)
  - Unread message badges
  - Last message preview with relative time
  - Status color coding
  - Pagination-ready

**`src/views/merchant/chats/ChatDetail.vue`** - Conversation detail page
- Sections:
  - Header: Buyer info, jasa title, status badge
  - Message thread: Auto-scrolling, buyer/merchant differentiation
  - Offer form: Conditional on pending_offer status
  - Message input: Send button with keyboard support
- Features:
  - Relative time formatting ("5m ago", "2h ago")
  - Price formatting (Indonesian locale)
  - Auto-scroll to latest message
  - Status indicators with styling

#### 3. Routes
Added to `src/router/index.js` under merchant center:
```javascript
{
  path: "chats",
  name: "Merchant Chat",
  component: Index,
  meta: { title: "Chat dengan Pembeli | SUMILIR" }
},
{
  path: "chats/:conversationId",
  name: "Merchant Chat Detail",
  component: ChatDetail,
  meta: { title: "Detail Chat | SUMILIR" }
}
```

#### 4. Navigation
Menu item in `src/layouts/MerchantLayout.vue`:
```javascript
{
  label: "Chat dengan Pembeli",
  icon: "pi-comments",
  route: `/merchant-center/{merchantSlug}/chats`
}
```

## Database Schema

### Conversations Table
```sql
id (bigint, PK)
buyer_id (bigint, FK → users)
merchant_id (bigint, FK → users)
jasa_id (bigint, nullable, FK → jasas)
status (varchar, enum: active|pending_offer|deal_accepted|completed|cancelled)
last_message_at (timestamp, nullable)
created_at (timestamp)
updated_at (timestamp)

Indexes: buyer_id, merchant_id, jasa_id, status
```

### Messages Table
```sql
id (bigint, PK)
conversation_id (bigint, FK → conversations)
sender_id (bigint, FK → users)
sender_role (varchar: buyer|customer|merchant)
type (varchar, default: message, values: message|offer|system)
body (text)
jasa_id (bigint, nullable)
offer_price (int, nullable)
offer_status (varchar, nullable: pending|accepted|rejected)
is_read (boolean, default: false)
read_at (timestamp, nullable)
created_at (timestamp)
updated_at (timestamp)

Indexes: conversation_id, sender_id, (conversation_id, is_read)
```

## API Response Examples

### List Conversations
```json
{
  "data": [
    {
      "id": 1,
      "buyer_id": 5,
      "merchant_id": 2,
      "jasa_id": 10,
      "status": "active",
      "user": {
        "id": 5,
        "name": "Ahmad Rizki",
        "profile_picture": "http://api.sumilir.local/api/profile-pictures/5"
      },
      "jasa": {
        "id": 10,
        "title": "Jasa Layanan",
        "slug": "jasa-layanan"
      },
      "last_message": {
        "id": 42,
        "body": "Berapa harganya?",
        "sender_role": "buyer",
        "created_at": "2026-02-24T10:30:00Z"
      },
      "unread_count": 2,
      "created_at": "2026-02-20T08:15:00Z",
      "updated_at": "2026-02-24T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 45,
    "per_page": 15
  }
}
```

### Get Conversation with Messages
```json
{
  "data": {
    "conversation": {
      "id": 1,
      "buyer_id": 5,
      "merchant_id": 2,
      "jasa_id": 10,
      "status": "pending_offer",
      "user": { ... },
      "jasa": { ... },
      "created_at": "2026-02-20T08:15:00Z",
      "updated_at": "2026-02-24T10:30:00Z"
    },
    "messages": [
      {
        "id": 40,
        "sender_id": 5,
        "sender_role": "buyer",
        "body": "Apakah bisa lebih murah?",
        "is_read": true,
        "offer_price": null,
        "offer_status": null,
        "created_at": "2026-02-24T10:25:00Z",
        "sender": { ... }
      },
      {
        "id": 41,
        "sender_id": 2,
        "sender_role": "merchant",
        "body": "💰 Penawaran harga: Rp 250.000\n\n📝 Untuk paket lengkap",
        "is_read": true,
        "offer_price": 250000,
        "offer_status": "pending",
        "created_at": "2026-02-24T10:28:00Z",
        "sender": { ... }
      }
    ]
  }
}
```

## Usage Flow

### Merchant Workflow
1. Navigate to "Chat dengan Pembeli" in merchant sidebar
2. View list of conversations with buyers
3. Search/filter conversations as needed
4. Click on a conversation to view message thread
5. Read previous messages
6. Send responses to buyer inquiries
7. Submit price offer if needed
8. Accept offers from buyers
9. Update conversation status to track progress

### Offer Flow
1. Merchant sends message with price information
2. Merchant clicks "Buat Penawaran" button
3. Form appears: Price (required) + Note (optional)
4. After submission, conversation status → "pending_offer"
5. System adds offer message to thread
6. Buyer can accept/reject offer (buyer-side implementation)
7. Upon acceptance, status → "deal_accepted"

## API Request Examples

### Send Message
```bash
curl -X POST http://api.sumilir.local/api/chats/1/messages \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d {
    "body": "Baik, saya siap"}
  }
```

### Submit Offer
```bash
curl -X POST http://api.sumilir.local/api/chats/1/offer \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d {
    "price": 150000,
    "note": "Untuk paket premium dengan konsultasi"
  }
```

### Accept Offer
```bash
curl -X PUT http://api.sumilir.local/api/chats/1/offer/accept \
  -H "Authorization: Bearer {token}"
```

## Status Values

- **`active`** - Normal conversation, no pending offer
- **`pending_offer`** - Merchant has submitted price offer, awaiting response
- **`deal_accepted`** - Buyer accepted the offer
- **`completed`** - Service completed
- **`cancelled`** - Conversation cancelled

## Security Features

- ✅ Authentication required (all endpoints)
- ✅ Merchant authorization (can only access own conversations)
- ✅ Admin role override (admins can view all chats)
- ✅ Message ownership (verifies sender role)
- ✅ Request validation (price, body, status)
- ✅ Soft delete support (delete conversation)

## Performance Optimizations

- Eager loading relationships (buyer, jasa, messages, sender)
- Indexed database queries (conversation_id, sender_id, status)
- Pagination (15 items per page)
- Selective field selection (only needed columns loaded)
- Type filtering in queries (only 'message' type in threads)

## Frontend Lifecycle

1. **Mount**: Load conversations on page open
2. **List View**: 
   - Display all conversations
   - Enable search/filter
   - Click to open detail
3. **Detail View**:
   - Fetch conversation with messages
   - Auto-mark as read
   - Listen for new messages
   - Enable reply/offer submission
4. **Message Send**:
   - Validate input
   - POST to API
   - Update local state
   - Scroll to new message
5. **Offer Submit**:
   - Validate price
   - POST to API
   - Update conversation status
   - Reload conversation

## Related Files

### Backend
- Models: `app/Models/Conversation.php`, `app/Models/Message.php`
- Controller: `app/Http/Controllers/ChatController.php`
- Routes: `routes/api.php` (lines 240-248)

### Frontend
- Composable: `src/composables/useChat.js`
- Components: `src/views/merchant/chats/Index.vue`, `ChatDetail.vue`
- Router: `src/router/index.js`
- Layout: `src/layouts/MerchantLayout.vue`

## Testing Endpoints

```bash
# List conversations
GET /api/chats

# Get single conversation
GET /api/chats/1

# Send message
POST /api/chats/1/messages
body: { "body": "Pesan anda" }

# Submit offer
POST /api/chats/1/offer
body: { "price": 100000, "note": "opsional" }

# Accept offer
PUT /api/chats/1/offer/accept

# Update status
PUT /api/chats/1/status
body: { "status": "completed" }

# Delete conversation
DELETE /api/chats/1
```

## Next Steps (Future Enhancements)

- [ ] Real-time messaging (WebSocket/Broadcasting)
- [ ] Chat notifications (Web notifications)
- [ ] File/image uploads in messages
- [ ] Message reactions/reactions
- [ ] Conversation search in API
- [ ] Message deletion/editing
- [ ] Bulk message export
- [ ] Chat templates/quick replies
- [ ] Business hours status
- [ ] Customer side chat interface
- [ ] Chat typing indicators

## Notes

- Conversation table already existed in database
- Message table already existed with extended schema
- Adapted models to work with existing schema
- API follows RESTful conventions
- Responses include pagination metadata
- All text fields support Indonesian characters (UTF-8)
- Time formatting uses Indonesian locale
- Price formatting uses Indonesian number format (Rp with . separator)
