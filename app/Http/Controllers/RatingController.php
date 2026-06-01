<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\RatingSummary;
use App\Models\Product;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\ReviewMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Helpers\ApiResponse;

class RatingController extends Controller
{
    /**
     * GET /api/public/products/{productId}/ratings
     * Dapatkan semua rating untuk produk tertentu (accepts slug or id)
     */
    public function indexForProduct($productId)
    {
        // Support both id and slug
        $product = Product::where('id', $productId)
            ->orWhere('slug', $productId)
            ->first();

        if (!$product) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $ratings = Rating::where('rateable_id', $product->id)
            ->where('rateable_type', Product::class)
            ->with(['user', 'media'])
            ->latest()
            ->paginate(10);

        return response()->json($ratings);
    }

    /**
     * GET /api/public/jasas/{jasaId}/ratings
     * Dapatkan semua rating untuk jasa tertentu (accepts slug or id)
     */
    public function indexForJasa($jasaId)
    {
        // Support both id and slug
        $jasa = Jasa::where('id', $jasaId)
            ->orWhere('slug', $jasaId)
            ->first();

        if (!$jasa) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $ratings = Rating::where('rateable_id', $jasa->id)
            ->where('rateable_type', Jasa::class)
            ->with(['user', 'media'])
            ->latest()
            ->paginate(10);

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
    public function indexForMerchantBySlug($merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)->first();

        if (!$merchant) {
            return response()->json([
                'data' => [],
                'total' => 0,
            ]);
        }

        $ratings = Rating::where('merchant_id', $merchant->id)
            ->with(['user', 'rateable', 'media'])
            ->latest()
            ->paginate(10);

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
            'order_id' => 'nullable|integer',
            // media is nullable — can be single UploadedFile or array of files
            // Normalize to array in controller logic (see below)
            'media' => 'nullable',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,webp,mp4,avi,mov,mkv|max:10240',
        ]);

        // Validasi: pengguna sudah beli produk ini
        // TODO: Implement order validation
        // $hasOrder = Order::where('user_id', $user->id)
        //     ->whereHas('items', function ($q) use ($data) {
        //         if ($data['rateable_type'] === 'App\\Models\\Product') {
        //             $q->where('product_id', $data['rateable_id']);
        //         }
        //     })
        //     ->exists();
        
        // if (!$hasOrder) {
        //     return response()->json(['message' => 'Anda harus membeli produk ini terlebih dahulu'], 403);
        // }

        // Check: sudah pernah rating?
        $existingRating = Rating::where('user_id', $user->id)
            ->where('rateable_id', $data['rateable_id'])
            ->where('rateable_type', $data['rateable_type'])
            ->exists();

        if ($existingRating) {
            return response()->json([
                'message' => 'Anda sudah memberikan rating untuk produk ini'
            ], 422);
        }

        // Tambahkan user_id dan merchant_id
        $data['user_id'] = $user->id;

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

        Log::info('[RatingController::store] Processing media files:', [
            'count' => count($mediaFiles),
            'type' => gettype($rawMedia),
        ]);

        foreach ($mediaFiles as $index => $file) {
            $path = $file->store('review-media', 'public');
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
        $rating = Rating::with('user')->findOrFail($ratingId);

        return response()->json($rating);
    }

    /**
     * PUT /api/ratings/{ratingId}
     * Update rating (only by owner)
     */
    public function update(Request $request, $ratingId)
    {
        $user = $request->user();
        $rating = Rating::findOrFail($ratingId);

        // Validasi: hanya pemberi rating yang bisa edit
        if ($rating->user_id !== $user->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk mengubah rating ini'
            ], 403);
        }

        $data = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:2000',
        ]);

        $rating->update($data);

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
            'data' => $rating
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
}
