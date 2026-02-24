<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\RatingSummary;
use App\Models\Product;
use App\Models\Jasa;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RatingController extends Controller
{
    /**
     * GET /api/products/{productId}/ratings
     * Dapatkan semua rating untuk produk tertentu
     */
    public function indexForProduct($productId)
    {
        $ratings = Rating::where('rateable_id', $productId)
            ->where('rateable_type', 'App\\Models\\Product')
            ->with('user')
            ->latest()
            ->paginate(10);

        return response()->json($ratings);
    }

    /**
     * GET /api/jasas/{jasaId}/ratings
     * Dapatkan semua rating untuk jasa tertentu
     */
    public function indexForJasa($jasaId)
    {
        $ratings = Rating::where('rateable_id', $jasaId)
            ->where('rateable_type', 'App\\Models\\Jasa')
            ->with('user')
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
        $merchant = Merchant::where('slug', $merchantSlug)->firstOrFail();

        return $this->merchantOverall($merchant->id);
    }

    /**
     * GET /api/merchants/{merchantSlug}/ratings
     * Dapatkan semua individual ratings untuk produk/jasa merchant
     */
    public function indexForMerchantBySlug($merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)->firstOrFail();

        $ratings = Rating::where('merchant_id', $merchant->id)
            ->with(['user', 'rateable'])
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
            'title' => 'nullable|string|max:255',
            'comment' => 'nullable|string|max:2000',
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

        // Buat rating
        $rating = Rating::create($data);

        // Update rating summary
        RatingSummary::updateFromRating($rating);

        return response()->json([
            'message' => 'Rating berhasil ditambahkan',
            'data' => $rating->load('user')
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

        // Update rating summary
        RatingSummary::updateFromRating($rating);

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

        // Update rating summary
        RatingSummary::updateFromRating(new Rating([
            'merchant_id' => $merchantId,
            'rateable_id' => $rateableId,
            'rateable_type' => $rateableType,
        ]));

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
