<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\RatingSummary;
use App\Models\Product;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\ReviewMedia;
use App\Models\Order;
use App\Models\ProductOrderItem;
use App\Models\JasaOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Helpers\ApiResponse;

class RatingController extends Controller
{
    /**
     * GET /api/public/products/{productId}/ratings
     * Dapatkan semua rating untuk produk tertentu (accepts slug or id)
     */
    public function indexForProduct(Request $request, $productId)
    {
        // Support both id and slug
        $product = Product::where('id', $productId)
            ->orWhere('slug', $productId)
            ->first();

        if (!$product) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Rating::where('rateable_id', $product->id)
            ->where('rateable_type', Product::class)
            ->with(['user', 'media', 'histories']);

        $this->applyFilters($request, $query);

        $perPage = $request->query('per_page', 10);
        $ratings = $query->paginate($perPage);

        return response()->json($ratings);
    }

    /**
     * GET /api/public/jasas/{jasaId}/ratings
     * Dapatkan semua rating untuk jasa tertentu (accepts slug or id)
     */
    public function indexForJasa(Request $request, $jasaId)
    {
        // Support both id and slug
        $jasa = Jasa::where('id', $jasaId)
            ->orWhere('slug', $jasaId)
            ->first();

        if (!$jasa) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Rating::where('rateable_id', $jasa->id)
            ->where('rateable_type', Jasa::class)
            ->with(['user', 'media', 'histories']);

        $this->applyFilters($request, $query);

        $perPage = $request->query('per_page', 10);
        $ratings = $query->paginate($perPage);

        return response()->json($ratings);
    }

    /**
     * GET /api/merchants/{merchantId}/ratings
     * Dapatkan akumulasi rating merchant (dari semua produk/jasa)
     */
    public function merchantOverall($merchantId)
    {
        $stats = RatingSummary::updateMerchantOverall($merchantId);

        return response()->json($stats);
    }

    /**
     * GET /api/merchants/{merchantId}/rating-summary
     * Alias untuk merchantOverall
     */
    public function merchantSummary($merchantId)
    {
        return $this->merchantOverall($merchantId);
    }

    /**
     * GET /api/merchants/{merchantSlug}/ratings/summary
     * Dapatkan rating merchant berdasarkan slug (untuk frontend)
     */
    public function merchantSummaryBySlug($merchantSlug)
    {
        $stats = RatingSummary::updateMerchantOverallBySlug($merchantSlug);

        // Wrapped in ApiResponse format for frontend consistency
        return ApiResponse::success($stats, 'success');
    }

    /**
     * GET /api/merchants/{merchantSlug}/ratings
     * Dapatkan semua individual ratings untuk produk/jasa merchant
     */
    public function indexForMerchantBySlug(Request $request, $merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)->first();

        if (!$merchant) {
            return response()->json([
                'data' => [],
                'total' => 0,
            ]);
        }

        $query = Rating::where('merchant_id', $merchant->id)
            ->with(['user', 'rateable', 'media', 'histories']);

        // Filter ratings based on merchant segmentation type to avoid cross-UMKM reviews
        if ($merchant->segmentation_id == 3) {
            $query->where('rateable_type', 'App\\Models\\Jasa');
        } else {
            $query->where('rateable_type', 'App\\Models\\Product');
        }

        $this->applyFilters($request, $query);

        $perPage = $request->query('per_page', 10);
        $ratings = $query->paginate($perPage);

        return response()->json($ratings);
    }

    /**
     * POST /api/ratings
     * Buat rating baru
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'rateable_id' => 'required|integer',
            'rateable_type' => 'required|in:App\\Models\\Product,App\\Models\\Jasa',
            'merchant_id' => 'required|integer|exists:merchants,id',
            'rating' => 'required|integer|min:1|max:5',
            // title is optional (nullable); comment is required; is_anonymous is optional
            'title' => 'nullable|string|max:255',
            'comment' => 'required|string|min:10|max:2000',
            'is_anonymous' => 'nullable|boolean',
            'order_id' => 'required|integer|exists:orders,id',
            'order_item_id' => 'nullable|integer',
            'jasa_order_item_id' => 'nullable|integer',
            // media is nullable — can be single UploadedFile or array of files
            // Normalize to array in controller logic (see below)
            'media' => 'nullable',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:10240',
        ]);

        // Find order — must belong to customer
        $order = Order::where('id', $data['order_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan atau Anda bukan pemilik pesanan'], 404);
        }

        // Validate completion status
        if (!in_array($order->status, ['completed', 'selesai'])) {
            return response()->json(['message' => 'Pesanan harus diselesaikan terlebih dahulu sebelum memberikan ulasan'], 422);
        }

        $orderItemId = $data['order_item_id'] ?? null;
        $jasaOrderItemId = $data['jasa_order_item_id'] ?? null;

        // Resolve item from order and check if already reviewed
        if ($data['rateable_type'] === 'App\\Models\\Product') {
            if ($jasaOrderItemId !== null) {
                return response()->json(['message' => 'jasa_order_item_id harus null untuk rating produk'], 422);
            }

            if ($orderItemId !== null) {
                // Validate exists in product_order_items and belongs to this order
                $productItem = ProductOrderItem::where('id', $orderItemId)
                    ->where('order_id', $order->id)
                    ->first();
                if (!$productItem) {
                    return response()->json(['message' => 'Item pesanan produk tidak valid atau tidak sesuai dengan order_id'], 422);
                }
            } else {
                // Fallback guard: resolve item from order_id + product_id (rateable_id)
                $productItem = ProductOrderItem::where('order_id', $order->id)
                    ->where('product_id', $data['rateable_id'])
                    ->first();

                if (!$productItem) {
                    return response()->json(['message' => 'Produk tidak ditemukan dalam pesanan ini'], 422);
                }

                $orderItemId = $productItem->id;
            }

            $existingRating = Rating::where('order_item_id', $orderItemId)
                ->exists();
        } else {
            if ($orderItemId !== null) {
                return response()->json(['message' => 'order_item_id harus null untuk rating jasa'], 422);
            }

            if ($jasaOrderItemId !== null) {
                // Validate exists in jasa_order_items and belongs to this order
                $jasaItem = JasaOrderItem::where('id', $jasaOrderItemId)
                    ->where('order_id', $order->id)
                    ->first();
                if (!$jasaItem) {
                    return response()->json(['message' => 'Item pesanan jasa tidak valid atau tidak sesuai dengan order_id'], 422);
                }
            } else {
                // Fallback guard: resolve item from order_id + jasa_id (rateable_id)
                $jasaItem = JasaOrderItem::where('order_id', $order->id)
                    ->where('jasa_id', $data['rateable_id'])
                    ->first();

                if (!$jasaItem) {
                    return response()->json(['message' => 'Jasa tidak ditemukan dalam pesanan ini'], 422);
                }

                $jasaOrderItemId = $jasaItem->id;
            }

            $existingRating = Rating::where('order_id', $order->id)
                ->where('jasa_order_item_id', $jasaOrderItemId)
                ->where('user_id', $user->id)
                ->exists();

            if (!$existingRating) {
                $existingRating = Rating::where('jasa_order_item_id', $jasaOrderItemId)
                    ->where('user_id', $user->id)
                    ->exists();
            }
        }

        if ($existingRating) {
            return response()->json([
                'message' => 'Anda sudah memberikan rating untuk pesanan ini'
            ], 422);
        }

        // Tambahkan user_id, order_item_id, dan jasa_order_item_id
        $data['user_id'] = $user->id;
        $data['order_item_id'] = $orderItemId;
        $data['jasa_order_item_id'] = $jasaOrderItemId;

        // Konversi is_anonymous dari string '1'/'0' ke boolean
        if (isset($data['is_anonymous'])) {
            $val = $data['is_anonymous'];
            if (is_string($val)) {
                $data['is_anonymous'] = in_array(strtolower($val), ['1', 'true', 'yes']);
            } else {
                $data['is_anonymous'] = (bool) $val;
            }
        }

        // Buat rating
        $rating = Rating::create($data);

        // Update JasaOrderItem flags if Jasa
        if ($jasaOrderItemId) {
            JasaOrderItem::where('id', $jasaOrderItemId)->update([
                'is_reviewed' => true,
                'review_id' => $rating->id,
            ]);
        }

        // Handle media uploads
        // Normalize to array: single UploadedFile → array, already array → use as-is
        $rawMedia = $request->file('media');
        $mediaFiles = [];
        if ($rawMedia) {
            if (is_array($rawMedia)) {
                $mediaFiles = $rawMedia;
            } elseif ($rawMedia instanceof \Illuminate\Http\UploadedFile) {
                $mediaFiles = [$rawMedia];
            }
        }

        Log::info('[RatingController::store] Processing media files', [
            'count' => count($mediaFiles),
            'type'  => gettype($rawMedia),
        ]);

        foreach ($mediaFiles as $index => $file) {
            $path = $file->store('reviews', 'public');
            $fileUrl = asset('storage/' . $path);
            $fileType = str_starts_with($file->getMimeType(), 'image') ? 'image' : 'video';

            ReviewMedia::create([
                'review_id' => $rating->id,
                'file_path' => $path,
                'file_url' => $fileUrl,
                'file_type' => $fileType,
                'original_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'display_order' => $index,
            ]);
        }

        // Update rating summary for the item (legacy + polymorphic)
        RatingSummary::updateFromRating($rating);

        // Also update polymorphic summary for the rated item (jasa/product)
        if ($data['rateable_type'] === 'App\\Models\\Jasa') {
            $jasa = \App\Models\Jasa::find($data['rateable_id']);
            if ($jasa) {
                RatingSummary::updatePolymorphicSummary($jasa, $rating->merchant_id);
            }
        } elseif ($data['rateable_type'] === 'App\\Models\\Product') {
            $product = \App\Models\Product::find($data['rateable_id']);
            if ($product) {
                RatingSummary::updatePolymorphicSummary($product, $rating->merchant_id);
            }
        }

        // Update merchant's overall rating summary
        $merchant = Merchant::find($rating->merchant_id);
        if ($merchant) {
            RatingSummary::updatePolymorphicSummary($merchant, $merchant->id);
        }

        return response()->json([
            'message' => 'Rating berhasil ditambahkan',
            'data' => $rating->load(['user', 'media'])
        ], 201);
    }

    /**
     * GET /api/ratings/{ratingId}
     * Lihat detail rating
     */
    public function show($ratingId)
    {
        $user = auth()->user();
        $rating = Rating::with(['user', 'media', 'rateable', 'orderItem', 'jasaOrderItem'])
            ->findOrFail($ratingId);

        // Only include user data if not anonymous, or if viewer is the owner
        $isOwner = $user && $rating->user_id === $user->id;

        $response = $rating->toArray();

        // Strip user data if anonymous and not owner
        if ($rating->is_anonymous && !$isOwner) {
            unset($response['user']);
        }

        // Always include polymorphic rateable relation for universal FE
        $response['rateable_type'] = $rating->rateable_type;
        $response['rateable'] = $rating->rateable;
        $response['rateable_image'] = $this->getRateableImageUrl($rating->rateable);

        // Include order item image for product orders
        if ($rating->orderItem) {
            $response['item_image'] = $rating->orderItem->image_snapshot_path
                ?? $rating->orderItem->image_url
                ?? $rating->orderItem->product?->image_url
                ?? $rating->orderItem->product?->image
                ?? null;
        }

        // Include jasa order item image for service orders - gunakan SNAPSHOT accessor
        if ($rating->jasaOrderItem) {
            // jasa_image_url accessor: snapshot > coverImage > image_url > image
            $response['item_image'] = $rating->jasaOrderItem->jasa_image_url
                ?? $rating->jasaOrderItem->jasa_image_snapshot
                ?? null;
            // Also include service title from snapshot for review display
            $response['item_title'] = $rating->jasaOrderItem->jasa_title;
            // Include merchant name from snapshot
            $response['merchant_name'] = $rating->jasaOrderItem->merchant_name;
        }

        return response()->json([
            'data' => $response,
        ]);
    }

    /**
     * Get item image URL from polymorphic rateable (Product/Jasa).
     */
    private function getRateableImageUrl($rateable): ?string
    {
        if (!$rateable) return null;
        // Try multiple image fields depending on item type
        $fields = [
            'logo_url',
            'cover_url',
            'image_url',
            'image',
            'thumbnail_url',
        ];
        foreach ($fields as $field) {
            if (!empty($rateable->{$field})) return $rateable->{$field};
        }
        // Try first image from images relation
        if ($rateable->relationLoaded('images') && $rateable->images->isNotEmpty()) {
            return $rateable->images->first()->url ?? $rateable->images->first()->image_url ?? null;
        }
        // Try cover_img
        if ($rateable->relationLoaded('cover_img') && $rateable->cover_img) {
            return $rateable->cover_img->url ?? $rateable->cover_img->image_url ?? null;
        }
        return null;
    }

    /**
     * PUT /api/ratings/{ratingId}
     * Update rating (only by owner)
     */
    public function update(Request $request, $ratingId)
    {
        $user = $request->user();
        // Load media relation first so it can be captured in history
        $rating = Rating::with('media')->findOrFail($ratingId);

        // Validasi: hanya pemberi rating yang bisa edit
        if ($rating->user_id !== $user->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah rating ini'
            ], 403);
        }

        // Rule: ulasan hanya bisa diperbarui 1 kali
        if ($rating->isUpdateExhausted()) {
            return response()->json([
                'message' => 'Kesempatan pembaruan ulasan sudah digunakan'
            ], 403);
        }

        $data = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:2000',
            'is_anonymous' => 'nullable',
        ]);

        // Konversi is_anonymous dari string '1'/'0' ke boolean
        if (array_key_exists('is_anonymous', $data)) {
            $val = $data['is_anonymous'];
            if (is_string($val)) {
                $data['is_anonymous'] = in_array(strtolower($val), ['1', 'true', 'yes']);
            } elseif ($val === null) {
                unset($data['is_anonymous']);
            } else {
                $data['is_anonymous'] = (bool) $val;
            }
        }

        // Process new media uploads first, retrieve their database IDs
        $rawMedia = $request->file('media')
            ?? $request->file('images')
            ?? $request->file('files')
            ?? [];
        if ($rawMedia && !is_array($rawMedia)) {
            $rawMedia = [$rawMedia];
        }

        $newMediaIds = [];
        if (is_array($rawMedia) && count($rawMedia) > 0) {
            $currentCount = $rating->media()->count();
            $maxFiles = 5;
            foreach ($rawMedia as $index => $file) {
                if (!($file instanceof \Illuminate\Http\UploadedFile)) continue;
                if ($currentCount + $index >= $maxFiles) break;

                $path = $file->store('reviews', 'public');
                $fileUrl = asset('storage/' . $path);
                $fileType = str_starts_with($file->getMimeType(), 'image') ? 'image' : 'video';

                $mediaObj = ReviewMedia::create([
                    'review_id' => $rating->id,
                    'file_path' => $path,
                    'file_url' => $fileUrl,
                    'file_type' => $fileType,
                    'original_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'display_order' => $currentCount + $index,
                ]);
                $newMediaIds[] = $mediaObj->id;
            }
        }

        // Update ulasan using the history tracking method
        $rating->updateWithHistory($data, $user->id, $newMediaIds);

        // Process removed_media_ids after history is created
        $removedIds = [];
        $rawRemoved = $request->input('removed_media_ids');
        if ($rawRemoved) {
            if (is_string($rawRemoved)) {
                $decoded = json_decode($rawRemoved, true);
                $removedIds = is_array($decoded) ? $decoded : [];
            } elseif (is_array($rawRemoved)) {
                $removedIds = $rawRemoved;
            }
        }
        if (!empty($removedIds)) {
            $mediaToDelete = $rating->media()->whereIn('id', $removedIds)->get();
            foreach ($mediaToDelete as $media) {
                if ($media->file_path && Storage::disk('public')->exists($media->file_path)) {
                    Storage::disk('public')->delete($media->file_path);
                }
                $media->delete();
            }
        }

        // Update rating summary for the item (legacy + polymorphic)
        RatingSummary::updateFromRating($rating);

        // Also update polymorphic summary for the rated item
        if ($rating->rateable_type === 'App\\Models\\Jasa') {
            $jasa = \App\Models\Jasa::find($rating->rateable_id);
            if ($jasa) {
                RatingSummary::updatePolymorphicSummary($jasa, $rating->merchant_id);
            }
        } elseif ($rating->rateable_type === 'App\\Models\\Product') {
            $product = \App\Models\Product::find($rating->rateable_id);
            if ($product) {
                RatingSummary::updatePolymorphicSummary($product, $rating->merchant_id);
            }
        }

        // Update merchant's overall rating summary
        $merchant = Merchant::find($rating->merchant_id);
        if ($merchant) {
            RatingSummary::updatePolymorphicSummary($merchant, $merchant->id);
        }

        return response()->json([
            'message' => 'Rating berhasil diperbarui',
            'data' => $rating->load(['user', 'media'])
        ]);
    }

    /**
     * DELETE /api/ratings/{ratingId}
     * Hapus rating (only by owner)
     */
    public function destroy($ratingId)
    {
        $user = auth()->user();
        $rating = Rating::findOrFail($ratingId);

        // Validasi: hanya pemberi rating yang bisa hapus
        if ($rating->user_id !== $user->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk menghapus rating ini'
            ], 403);
        }

        $merchantId = $rating->merchant_id;
        $rateableId = $rating->rateable_id;
        $rateableType = $rating->rateable_type;

        $rating->delete();

        // Update rating summary (legacy)
        RatingSummary::updateFromRating(new Rating([
            'merchant_id' => $merchantId,
            'rateable_id' => $rateableId,
            'rateable_type' => $rateableType,
        ]));

        // Also update polymorphic summary for the rated item
        if ($rateableType === 'App\\Models\\Jasa') {
            $jasa = \App\Models\Jasa::find($rateableId);
            if ($jasa) {
                RatingSummary::updatePolymorphicSummary($jasa, $merchantId);
            }
        } elseif ($rateableType === 'App\\Models\\Product') {
            $product = \App\Models\Product::find($rateableId);
            if ($product) {
                RatingSummary::updatePolymorphicSummary($product, $merchantId);
            }
        }

        // Update merchant's overall rating summary
        if ($merchantId) {
            $merchant = \App\Models\Merchant::find($merchantId);
            if ($merchant) {
                RatingSummary::updatePolymorphicSummary($merchant, $merchant->id);
            }
        }

        return response()->json([
            'message' => 'Rating berhasil dihapus'
        ]);
    }

    /**
     * GET /api/ratings/product/{productId}/summary
     * Ringkasan rating untuk product
     */
    public function productSummary($productId)
    {
        $summary = RatingSummary::where('rateable_id', $productId)
            ->where('rateable_type', 'App\\Models\\Product')
            ->firstOrFail();

        return response()->json($summary);
    }

    /**
     * GET /api/ratings/jasa/{jasaId}/summary
     * Ringkasan rating untuk jasa
     */
    public function jasaSummary($jasaId)
    {
        $summary = RatingSummary::where('rateable_id', $jasaId)
            ->where('rateable_type', 'App\\Models\\Jasa')
            ->firstOrFail();

        return response()->json($summary);
    }

    /**
     * POST /api/merchant/{merchant}/reviews/{ratingId}/reply
     * Merchant membalas tanggapan pelanggan (satu kali saja)
     */
    public function merchantReply(Request $request, Merchant $merchant, $ratingId)
    {
        // Validate merchant ownership
        if ($merchant->user_id !== auth()->id()) {
            return ApiResponse::error('Tidak memiliki akses ke ulasan ini', 403);
        }

        // Find the rating
        // NOTE: jasaOrderItem.jasa not eager-loaded - use snapshot accessors for historical data
        $rating = Rating::with(['media', 'user', 'jasaOrderItem'])
            ->find($ratingId);

        if (!$rating) {
            return ApiResponse::error('Ulasan tidak ditemukan', 404);
        }

        // Validate rating belongs to this merchant
        if ($rating->merchant_id !== $merchant->id) {
            return ApiResponse::error('Ulasan ini bukan untuk merchant Anda', 403);
        }

        // Check if merchant already replied
        if ($rating->hasMerchantReply()) {
            return ApiResponse::error('Ulasan ini sudah ditanggapi. Ulasan hanya dapat ditanggapi satu kali.', 422);
        }

        // Validate request
        $data = $request->validate([
            'merchant_reply' => 'required|string|max:1000',
        ]);

        // Update rating with merchant reply
        $rating->update([
            'merchant_reply' => $data['merchant_reply'],
            'merchant_reply_at' => now(),
        ]);

        // Reload with relations
        $rating->load(['media', 'user']);

        Log::info('[RatingController::merchantReply] Reply submitted', [
            'rating_id' => $rating->id,
            'merchant_id' => $merchant->id,
            'reply_length' => strlen($data['merchant_reply']),
        ]);

        return ApiResponse::success([
            'id' => $rating->id,
            'rating' => $rating->rating,
            'comment' => $rating->comment,
            'merchant_reply' => $rating->merchant_reply,
            'merchant_reply_at' => $rating->merchant_reply_at?->toIso8601String(),
        ], 'Tanggapan ulasan berhasil dikirim');
    }

    /**
     * Helper to apply universal filters & sorting to ratings query.
     */
    private function applyFilters(Request $request, $query)
    {
        // 1. Filter by segment_type / type (useful for merchant level)
        $segmentType = $request->query('segment_type') ?? $request->query('type');
        if ($segmentType) {
            $segmentType = strtolower($segmentType);
            if ($segmentType === 'jasa') {
                $query->where('rateable_type', 'App\\Models\\Jasa');
            } elseif ($segmentType === 'produk' || $segmentType === 'kuliner') {
                $segmentationId = $segmentType === 'produk' ? 1 : 2;
                $query->where('rateable_type', 'App\\Models\\Product')
                    ->whereHas('merchant', function ($q) use ($segmentationId) {
                        $q->where('segmentation_id', $segmentationId);
                    });
            }
        }

        // 2. Filter by rating (1..5)
        $rating = $request->query('rating');
        if ($rating) {
            $query->where('rating', (int)$rating);
        }

        // 3. Filter by has_media (true/false)
        $hasMedia = $request->query('has_media');
        if ($hasMedia !== null) {
            if ($hasMedia === 'true' || $hasMedia === '1') {
                $query->whereHas('media');
            } elseif ($hasMedia === 'false' || $hasMedia === '0') {
                $query->whereDoesntHave('media');
            }
        }

        // 4. Filter by has_reply (true/false)
        $hasReply = $request->query('has_reply');
        if ($hasReply !== null) {
            if ($hasReply === 'true' || $hasReply === '1') {
                $query->whereNotNull('merchant_reply')->where('merchant_reply', '!=', '');
            } elseif ($hasReply === 'false' || $hasReply === '0') {
                $query->where(function ($q) {
                    $q->whereNull('merchant_reply')->orWhere('merchant_reply', '');
                });
            }
        }

        // 5. Sort (newest | oldest)
        $sort = $request->query('sort', 'newest');
        if (strtolower($sort) === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
    }
}
