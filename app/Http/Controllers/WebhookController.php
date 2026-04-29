<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Payment;
use App\Models\MerchantWalletHistory;
use App\Models\Payout;

class WebhookController extends Controller
{
    public function callback(Request $request)
    {
        // 🔐 1. VALIDASI CALLBACK TOKEN
        $callbackToken = (string) $request->header('x-callback-token', '');
        $expectedToken = (string) config('services.xendit.callback_token', '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $callbackToken)) {
            return ApiResponse::error('Unauthorized', 403);
        }

        $data = $request->all();

        $externalId = $data['external_id'] ?? null;

        if (!$externalId) {
            return ApiResponse::error('Invalid payload', 400);
        }

        // 🔹 HANDLE PAYMENT (order-xxx)
        if (str_starts_with($externalId, 'order-')) {
            return $this->handlePaymentWebhook($data);
        }

        // 🔹 HANDLE PAYOUT (payout-xxx)
        if (str_starts_with($externalId, 'payout-')) {
            return $this->handlePayoutWebhook($data);
        }

        return ApiResponse::success(null, 'Ignored');
    }

    /**
     * HANDLE PAYMENT WEBHOOK
     */
    private function handlePaymentWebhook(array $data)
    {
        $externalId = $data['external_id'] ?? null;
        $status = strtoupper((string) ($data['status'] ?? ''));
        $amount = $data['amount'] ?? null;

        if (!$externalId || $status === '' || is_null($amount)) {
            return ApiResponse::error('Invalid payload', 400);
        }

        $payment = Payment::query()->where('external_id', $externalId)->first();

        if (!$payment) {
            return ApiResponse::error('Payment not found', 404);
        }

        // ❗ IDEMPOTENCY (ANTI DOUBLE TRIGGER)
        $processedStatusMap = [
            'paid' => 'PAID',
            'expired' => 'EXPIRED',
            'failed' => 'FAILED',
        ];
        $currentStatus = (string) $payment->status;
        if (isset($processedStatusMap[$currentStatus])) {
            if ($processedStatusMap[$currentStatus] === $status) {
                return ApiResponse::success(null, 'Already processed');
            }
            if ($currentStatus === 'paid') {
                return ApiResponse::success(null, 'Ignored');
            }
        }

        $order = $payment->order;
        if (!$order) {
            return ApiResponse::error('Order not found', 404);
        }

        if ((int) round((float) $amount) !== (int) round((float) $payment->amount)) {
            return ApiResponse::error('Invalid amount', 400);
        }

        if ($order->status !== 'pending' && $status === 'PAID') {
            return ApiResponse::error('Invalid order state', 400);
        }

        // 🔹 HANDLE PAID
        if ($status === 'PAID') {
            DB::transaction(function () use ($data, $externalId) {

                $payment = Payment::where('external_id', $externalId)
                    ->lockForUpdate()
                    ->first();

                if (!$payment || $payment->status === 'paid') {
                    return;
                }

                // 1. UPDATE PAYMENT
                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payment_method' => $data['payment_method'] ?? null,
                    'raw_response' => $data,
                ]);

                $order = $payment->order;
                if (!$order) {
                    return;
                }

                // 2. UPDATE ORDER
                $order->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                $merchant = $order->merchant;
                if (!$merchant) {
                    return;
                }

                $netAmount = (float) ($order->net_amount ?? 0);
                if ($netAmount <= 0) {
                    $netAmount = max(0, (float) $order->gross_amount - (float) $order->platform_fee);
                }

                // 3. MASUK KE BALANCE PENDING
                $merchant->increment('balance_pending', $netAmount);

                // 4. WALLET HISTORY
                MerchantWalletHistory::create([
                    'merchant_id' => $merchant->id,
                    'type' => 'credit',
                    'amount' => $netAmount,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'description' => 'Payment received (pending)',
                ]);
            });

            return ApiResponse::success(null, 'Payment processed');
        }

        // 🔹 HANDLE EXPIRED
        if ($status === 'EXPIRED') {
            $payment->update([
                'status' => 'expired',
                'raw_response' => $data,
            ]);

            if ($order->status === 'pending') {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }

            return ApiResponse::success(null, 'Payment expired');
        }

        // 🔹 HANDLE FAILED
        if ($status === 'FAILED') {
            $payment->update([
                'status' => 'failed',
                'raw_response' => $data,
            ]);

            return ApiResponse::success(null, 'Payment failed');
        }

        return ApiResponse::success(null, 'Unhandled status');
    }

    /**
     * HANDLE PAYOUT WEBHOOK
     */
    private function handlePayoutWebhook(array $data)
    {
        $externalId = $data['external_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$externalId || !$status) {
            return ApiResponse::error('Invalid payload', 400);
        }

        $payout = Payout::query()->where('external_id', $externalId)->first();

        if (!$payout) {
            return ApiResponse::error('Payout not found', 404);
        }

        // ❗ IDEMPOTENCY
        if ($payout->status === 'success') {
            return ApiResponse::success(null, 'Already processed');
        }

        // 🔹 SUCCESS
        if ($status === 'COMPLETED') {
            DB::transaction(function () use ($payout, $data) {

                $payout = Payout::query()
                    ->where('id', $payout->id)
                    ->lockForUpdate()
                    ->first();

                if (!$payout || $payout->status === 'success') {
                    return;
                }

                $payout->update([
                    'status' => 'success',
                    'processed_at' => now(),
                ]);

                $merchant = $payout->merchant;

                // 💸 KURANGI SALDO AVAILABLE
                $merchant->decrement('balance_available', $payout->amount);

                MerchantWalletHistory::create([
                    'merchant_id' => $merchant->id,
                    'type' => 'debit',
                    'amount' => $payout->amount,
                    'reference_type' => 'payout',
                    'reference_id' => $payout->id,
                    'description' => 'Payout to bank',
                ]);
            });

            return ApiResponse::success(null, 'Payout success');
        }

        // 🔹 FAILED
        if ($status === 'FAILED') {
            $payout->update([
                'status' => 'failed',
                'failure_reason' => $data['failure_reason'] ?? null,
            ]);

            return ApiResponse::success(null, 'Payout failed');
        }

        return ApiResponse::success(null, 'Unhandled payout status');
    }
}