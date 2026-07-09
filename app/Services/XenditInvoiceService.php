<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceConsultation;
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
        // Product/kuliner order harus masih pending.
        if ($order->order_type !== 'jasa' && $order->status !== 'pending') {
            throw new \RuntimeException('Order tidak valid atau sudah diproses');
        }

        // Flow jasa baru:
        // Invoice Xendit hanya boleh dibuat setelah merchant menerima pesanan.
        if ($order->order_type === 'jasa' && $order->status !== 'diterima') {
            throw new \RuntimeException('Pesanan jasa harus dikonfirmasi merchant terlebih dahulu sebelum pembayaran.');
        }

        $externalId = 'order-' . $order->id;

        $existingPayment = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existingPayment instanceof Payment) {
            if ($existingPayment->expired_at && now()->greaterThan($existingPayment->expired_at)) {
                $existingPayment->update(['status' => 'expired']);

                if ($order->order_type === 'jasa') {
                    $this->expireConsultationOrderIfNeeded($order);
                }

                throw new \RuntimeException('Batas waktu pembayaran sudah habis. Percakapan otomatis dihentikan.');
            }

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
        $amount = (int) round((float) (
            $order->total_payment_snapshot
            ?? $order->gross_amount
            ?? $order->total
            ?? $order->total_price
            ?? 0
        ));

        if ($amount <= 0) {
            $amount = 1;
        }

        if ($order->order_type === 'jasa') {
            $order->loadMissing('jasaItems.jasa');
            $jasaItem = $order->jasaItems->first();
            $serviceName = $jasaItem?->jasa_title_snapshot
                ?? $jasaItem?->jasa?->title
                ?? 'Layanan Jasa';
            $description = "Pembayaran Layanan: {$serviceName}";
        } else {
            $description = 'Order #' . ($order->order_code ?? $order->id);
        }

        $order->loadMissing('jasaItems');
        $isConsultationOrder = $order->order_type === 'jasa'
            && $order->jasaItems->contains(fn($item) => !empty($item->service_consultation_id));

        $invoiceDuration = $isConsultationOrder
            ? 3600
            : (int) config('services.xendit.invoice_duration', 7200);

        if ($invoiceDuration < 300) {
            $invoiceDuration = $isConsultationOrder ? 3600 : 7200;
        }

        $frontendUrl = rtrim((string) config('services.xendit.frontend_url', config('app.frontend_url', 'http://localhost:5173')), '/');

        if ($order->order_type === 'jasa') {
            $successRedirectUrl = $frontendUrl . '/orders/' . $order->id . '?type=jasa&payment=success';
            $failureRedirectUrl = $frontendUrl . '/orders/' . $order->id . '?type=jasa&payment=failed';
        } else {
            $successRedirectUrl = $frontendUrl . '/orders/' . $order->id . '?payment=success';
            $failureRedirectUrl = $frontendUrl . '/orders/' . $order->id . '?payment=failed';
        }

        $payload = [
            'external_id' => $externalId,
            'amount' => $amount,
            'description' => $description,
            'currency' => 'IDR',
            'invoice_duration' => $invoiceDuration,
            'success_redirect_url' => $successRedirectUrl,
            'failure_redirect_url' => $failureRedirectUrl,
        ];

        if ($order->user?->email) {
            $payload['payer_email'] = (string) $order->user->email;
            $payload['customer'] = [
                'email' => (string) $order->user->email,
                'given_name' => (string) ($order->customer_name_snapshot ?? $order->user_name_snapshot ?? $order->nama ?? $order->user->name ?? 'Customer'),
            ];
        }

        $paymentMethodsList = [
            'BCA',
            'BNI',
            'BRI',
            'MANDIRI',
            'PERMATA',
            'CIMB',
            'SAHABAT_SAMPOERNA',
            'ALFAMART',
            'INDOMARET',
            'OVO',
            'DANA',
            'SHOPEEPAY',
            'LINKAJA',
            'QRIS',
        ];

        $channel = strtoupper(
            $order->payment_channel
                ?? $order->payment_channel_snapshot
                ?? $order->payment_method
                ?? ''
        );

        // Normalize common values, e.g. BNI_VA / BNI VA -> BNI.
        $channel = str_replace(['_VA', ' VA'], '', $channel);

        if (in_array($channel, $paymentMethodsList, true)) {
            $payload['payment_methods'] = [$channel];
        }

        Log::info('[XenditInvoiceService] Creating invoice', [
            'external_id' => $externalId,
            'amount' => $amount,
            'description' => $description,
            'payment_methods' => $payload['payment_methods'] ?? null,
        ]);

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl . '/v2/invoices', $payload);

        if (!$response->successful()) {
            $errBody = $response->json();
            $errCode = $errBody['error_code'] ?? '';

            // Jika payment method belum aktif di Xendit, retry tanpa filter method.
            if ($errCode === 'UNAVAILABLE_PAYMENT_METHOD_ERROR' && isset($payload['payment_methods'])) {
                Log::warning('[XenditInvoiceService] Payment method unsupported, retrying without payment_methods filter', [
                    'external_id' => $externalId,
                    'unsupported_method' => $payload['payment_methods'],
                ]);

                unset($payload['payment_methods']);

                $response = Http::withBasicAuth($this->secretKey, '')
                    ->acceptJson()
                    ->asJson()
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
            'order_id' => $order->id,
            'external_id' => $externalId,
            'xendit_invoice_id' => $invoice['id'] ?? null,
            'invoice_url' => $invoice['invoice_url'] ?? null,
            'payment_method' => null,
            'expired_at' => now()->addSeconds($invoiceDuration),
            'amount' => $amount,
            'status' => 'pending',
            'raw_response' => $invoice,
        ]);
    }

    protected function expireConsultationOrderIfNeeded(Order $order): void
    {
        $order->loadMissing('jasaItems.serviceConsultation');

        $order->update([
            'status' => 'expired',
            'expired_at' => now(),
        ]);

        foreach ($order->jasaItems as $jasaItem) {
            $consultation = $jasaItem->serviceConsultation;
            if ($consultation instanceof ServiceConsultation) {
                $consultation->update([
                    'status' => ServiceConsultation::STATUS_CLOSED,
                    'closed_at' => now(),
                    'offer_status' => 'payment_expired',
                    'negotiation_notes' => trim(($consultation->negotiation_notes ?? '') . "\n[Auto] Percakapan dihentikan karena pembayaran melewati batas waktu 1 jam."),
                ]);
            }
        }
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
                'body' => $response->json(),
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
