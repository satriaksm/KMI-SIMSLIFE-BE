<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XenditInvoiceService
{
    protected string $baseUrl;
    protected string $secretKey;
    protected string $callbackToken;

    public function __construct()
    {
        $this->secretKey = (string) config('services.xendit.secret_key', '');
        $this->baseUrl = rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/');
        $this->callbackToken = (string) config('services.xendit.callback_token', '');
    }

    public function createOrGetPendingInvoice(Order $order): Payment
    {
        // For product/kuliner orders, validate status is pending.
        // For jasa orders, checkout status might be pending as well.
        if ($order->order_type !== 'jasa' && $order->status !== 'pending') {
            throw new \RuntimeException('Order tidak valid atau sudah diproses');
        }

        $externalId = 'order-' . $order->id;

        $existingPayment = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existingPayment instanceof Payment) {
            Log::info('[XenditInvoiceService] Reusing existing invoice', [
                'payment_id' => $existingPayment->id,
                'external_id' => $externalId,
            ]);
            return $existingPayment;
        }

        return $this->createInvoice($order, $externalId);
    }

    protected function createInvoice(Order $order, string $externalId): Payment
    {
        $amount = (int) ($order->total_payment_snapshot ?? $order->gross_amount ?? $order->total ?? $order->total_price ?? 0);
        if ($amount <= 0) {
            $amount = 1;
        }

        if ($order->order_type === 'jasa') {
            $order->loadMissing('jasaItems.jasa');
            $jasaItem = $order->jasaItems->first();
            $serviceName = $jasaItem?->jasa_title_snapshot ?? $jasaItem?->jasa?->title ?? 'Layanan Jasa';
            $description = "Pembayaran Layanan: {$serviceName}";
        } else {
            $description = 'Order #' . ($order->order_code ?? $order->id);
        }

        $invoiceDuration = (int) config('services.xendit.invoice_duration', 7200);
        if ($invoiceDuration < 300) {
            $invoiceDuration = 7200;
        }

        $frontendUrl = rtrim((string) config('services.xendit.frontend_url', config('app.frontend_url', 'http://localhost:5173')), '/');
        $successUrl = $frontendUrl . '/orders/' . $order->id . '?payment=success';
        $failureUrl = $frontendUrl . '/orders/' . $order->id . '?payment=failed';

        $payload = [
            'external_id' => $externalId,
            'amount' => $amount,
            'description' => $description,
            'currency' => 'IDR',
            'invoice_duration' => $invoiceDuration,
            'success_redirect_url' => $successUrl,
            'failure_redirect_url' => $failureUrl,
        ];

        // Add payment methods if matches
        $paymentMethodsList = [
            'BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'CIMB', 'SAHABAT_SAMPOERNA',
            'ALFAMART', 'INDOMARET', 'OVO', 'DANA', 'SHOPEEPAY', 'LINKAJA', 'QRIS'
        ];
        
        // Resolve target channel code from payment_channel, payment_channel_snapshot, or payment_method
        $channel = strtoupper(
            $order->payment_channel ??
            $order->payment_channel_snapshot ??
            $order->payment_method ??
            ''
        );
        
        // Clean up common variations (e.g. BNI_VA or BNI VA -> BNI)
        $channel = str_replace(['_VA', ' VA'], '', $channel);
        
        if (in_array($channel, $paymentMethodsList)) {
            $payload['payment_methods'] = [$channel];
        }

        // Add customer info
        if ($order->user?->email) {
            $payload['customer'] = [
                'email' => $order->user->email,
                'given_name' => $order->nama ?? $order->user->name ?? 'Customer',
            ];
        }

        Log::info('[XenditInvoiceService] Creating invoice', [
            'external_id' => $externalId,
            'amount' => $amount,
            'description' => $description,
        ]);

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->post($this->baseUrl . '/v2/invoices', $payload);

        if (!$response->successful()) {
            $errBody = $response->json();
            $errCode = $errBody['error_code'] ?? '';
            
            // If the specific payment method is unavailable/unsupported on this Xendit account,
            // fallback to general invoice (without restricting payment methods) and try again.
            if ($errCode === 'UNAVAILABLE_PAYMENT_METHOD_ERROR' && isset($payload['payment_methods'])) {
                Log::warning('[XenditInvoiceService] Payment method unsupported, retrying without payment_methods filter', [
                    'external_id' => $externalId,
                    'unsupported_method' => $payload['payment_methods'],
                ]);
                unset($payload['payment_methods']);
                
                $response = Http::withBasicAuth($this->secretKey, '')
                    ->acceptJson()
                    ->post($this->baseUrl . '/v2/invoices', $payload);
            }
        }

        if (!$response->successful()) {
            Log::error('[XenditInvoiceService] Xendit API error', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException($response->json('message') ?: 'Gagal membuat invoice Xendit');
        }

        $invoice = $response->json();

        return Payment::query()->create([
            'order_id'          => $order->id,
            'external_id'       => $externalId,
            'xendit_invoice_id' => $invoice['id'] ?? null,
            'invoice_url'       => $invoice['invoice_url'] ?? null,
            'payment_method'    => null,
            'expired_at'        => now()->addSeconds($invoiceDuration),
            'amount'            => $amount,
            'status'            => 'pending',
            'raw_response'      => $invoice,
        ]);
    }

    public function getInvoiceStatus(string $invoiceId): ?array
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->get($this->baseUrl . '/v2/invoices/' . $invoiceId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('[XenditInvoiceService] Failed to get invoice status', [
                'invoice_id' => $invoiceId,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('[XenditInvoiceService] Exception getting invoice status', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function validateCallbackToken(?string $token): bool
    {
        if (empty($this->callbackToken)) {
            return false;
        }
        return hash_equals($this->callbackToken, (string) $token);
    }
}
