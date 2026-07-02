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
        $status = $request->input('status', 'failed'); // failed, processing, succeeded

        $payments = Payment::query()
            ->with(['order.user'])
            ->whereNotNull('refund_status')
            ->when($status, function ($query, $status) {
                return $query->where('refund_status', $status);
            })
            ->latest('updated_at')
            ->paginate((int) $request->input('per_page', 15));

        return ApiResponse::success($payments, 'Berhasil mengambil daftar refund');
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
                'refund_status' => 'succeeded',
                'xendit_refund_id' => $response->json('id'),
            ]);

            return ApiResponse::success($payment, 'Proses refund manual berhasil dikirim melalui Disbursement');
        } catch (\Exception $e) {
            return ApiResponse::error('Terjadi kesalahan server: ' . $e->getMessage(), 500);
        }
    }
}
