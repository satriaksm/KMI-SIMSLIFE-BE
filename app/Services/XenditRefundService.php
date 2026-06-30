<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XenditRefundService
{
    /**
     * Process an automatic refund for an invoice payment.
     * 
     * @param Payment $payment
     * @param string $reason
     * @return bool True if refund is successfully processed/processing, False if failed.
     */
    public function processRefund(Payment $payment, string $reason = 'CANCELED_ORDER'): bool
    {
        if (!$payment->xendit_invoice_id) {
            return false;
        }

        try {
            $secretKey = config('services.xendit.secret_key');
            if (empty($secretKey)) {
                Log::error('[XenditRefundService] Secret key is missing');
                return false;
            }

            $response = Http::withBasicAuth($secretKey, '')
                ->post('https://api.xendit.co/refunds', [
                    'invoice_id' => $payment->xendit_invoice_id,
                    'reason' => $reason,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                $payment->update([
                    'refund_status' => $data['status'] === 'SUCCEEDED' ? 'succeeded' : 'processing',
                    'xendit_refund_id' => $data['id'] ?? null,
                ]);

                return true;
            }

            $errorMsg = $response->json('message') ?? 'Unknown error';
            $errorCode = $response->json('error_code') ?? 'UNKNOWN_ERROR';
            
            Log::warning('[XenditRefundService] Refund failed', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->xendit_invoice_id,
                'error_code' => $errorCode,
                'message' => $errorMsg,
            ]);

            // Set to failed so admin can manual refund it
            $payment->update([
                'refund_status' => 'failed',
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('[XenditRefundService] Exception during refund', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);

            $payment->update([
                'refund_status' => 'failed',
            ]);

            return false;
        }
    }
}
