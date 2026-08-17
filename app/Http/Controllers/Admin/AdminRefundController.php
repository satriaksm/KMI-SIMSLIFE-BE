<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AdminRefundController extends Controller
{
    /**
     * Get list of payments/orders that need manual refund
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'failed');
        $search = $request->input('search');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $sort = $request->input('sort', 'terbaru');

        $payments = Payment::query()
            ->with(['order.user', 'order.productItems.product', 'order.jasaItems.jasa'])
            ->whereNotNull('refund_status')
            ->when($status, function ($query, $status) {
                return $query->where('refund_status', $status);
            })
            ->when($search, function ($query, $search) {
                return $query->whereHas('order', function ($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($uq) use ($search) {
                          $uq->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            })
            ->when($startDate, function ($query, $startDate) {
                return $query->whereDate('updated_at', '>=', $startDate);
            })
            ->when($endDate, function ($query, $endDate) {
                return $query->whereDate('updated_at', '<=', $endDate);
            })
            ->when($sort, function ($query, $sort) {
                switch ($sort) {
                    case 'terlama':
                        return $query->oldest('updated_at');
                    case 'terbesar':
                        return $query->orderByDesc('amount');
                    case 'terkecil':
                        return $query->orderBy('amount');
                    case 'terbaru':
                    default:
                        return $query->latest('updated_at');
                }
            })
            ->paginate((int) $request->input('per_page', 15));

        $stats = [
            'failed_count' => Payment::where('refund_status', 'failed')->count(),
            'failed_amount' => Payment::where('refund_status', 'failed')->sum('amount'),
            'processing_count' => Payment::where('refund_status', 'processing')->count(),
            'processing_amount' => Payment::where('refund_status', 'processing')->sum('amount'),
            'succeeded_count' => Payment::where('refund_status', 'succeeded')->count(),
            'succeeded_amount' => Payment::where('refund_status', 'succeeded')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Berhasil mengambil daftar refund',
            'data' => $payments,
            'stats' => $stats,
        ]);
    }

    /**
     * Process a manual refund via Xendit Disbursement API
     */
    public function processManualRefund(Request $request, Payment $payment)
    {
        $request->validate([
            'bank_code' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
        ]);

        if ($payment->refund_status === 'succeeded') {
            return ApiResponse::error('Refund ini sudah berstatus berhasil', 400);
        }

        $externalId = 'refund-' . $payment->id . '-' . Str::random(5);
        $amount = $payment->amount;

        try {
            $response = Http::withBasicAuth(config('services.xendit.secret_key'), '')
                ->withHeaders(['X-IDEMPOTENCY-KEY' => $externalId])
                ->post('https://api.xendit.co/disbursements', [
                    'external_id' => $externalId,
                    'bank_code' => $request->input('bank_code'),
                    'account_holder_name' => $request->input('account_name'),
                    'account_number' => $request->input('account_number'),
                    'description' => 'Refund pesanan ' . ($payment->order->order_code ?? $payment->order_id),
                    'amount' => $amount,
                ]);

            if (!$response->successful()) {
                $errorMsg = $response->json('message') ?? 'Gagal memproses disbursement ke Xendit';
                return ApiResponse::error($errorMsg, 400);
            }

            $payment->update([
                'refund_status' => 'processing',
                'xendit_refund_id' => 'disb-' . $response->json('id'),
                'refund_destination' => $request->input('bank_code') . ' - ' . $request->input('account_number') . ' (' . $request->input('account_name') . ')',
            ]);

            return ApiResponse::success($payment, 'Proses refund manual berhasil dikirim melalui Disbursement');
        } catch (\Exception $e) {
            return ApiResponse::error('Terjadi kesalahan server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check Refund status manually from Xendit API
     */
    public function checkStatus(Request $request, Payment $payment)
    {
        if (!$payment->xendit_refund_id) {
            return ApiResponse::error('Tidak ada ID Refund Xendit untuk pesanan ini', 400);
        }

        try {
            $isDisbursement = str_starts_with($payment->xendit_refund_id, 'disb-');
            $actualId = $isDisbursement ? substr($payment->xendit_refund_id, 5) : $payment->xendit_refund_id;
            
            $endpoint = $isDisbursement 
                ? 'https://api.xendit.co/disbursements/' . $actualId
                : 'https://api.xendit.co/refunds/' . $actualId;

            $response = Http::withBasicAuth(config('services.xendit.secret_key'), '')
                ->get($endpoint);

            if ($response->successful()) {
                $status = $response->json('status');
                
                // Status berhasil pada Disbursement adalah COMPLETED, pada Refund adalah SUCCEEDED
                $isSuccess = ($isDisbursement && $status === 'COMPLETED') || (!$isDisbursement && $status === 'SUCCEEDED');
                
                if ($isSuccess && $payment->refund_status !== 'succeeded') {
                    $payment->update(['refund_status' => 'succeeded']);
                    return ApiResponse::success($payment, 'Status refund berhasil diperbarui menjadi Succeeded');
                }
                
                if ($status === 'FAILED' && $payment->refund_status !== 'failed') {
                    $payment->update(['refund_status' => 'failed']);
                    return ApiResponse::success($payment, 'Status refund berhasil diperbarui menjadi Failed');
                }

                return ApiResponse::success($payment, 'Status refund masih ' . $status . ' di Xendit');
            }

            return ApiResponse::error('Gagal mengecek status ke Xendit', 400);
        } catch (\Exception $e) {
            return ApiResponse::error('Terjadi kesalahan server: ' . $e->getMessage(), 500);
        }
    }
}
