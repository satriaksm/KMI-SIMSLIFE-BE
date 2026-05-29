<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class XenditInvoiceService
{
    public function createOrGetPendingInvoice(Order $order): Payment
    {
        if ($order->status !== 'pending') {
            throw new \RuntimeException('Order tidak valid atau sudah diproses');
        }

        $existingPayment = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existingPayment instanceof Payment) {
            return $existingPayment;
        }

        $invoiceDuration = (int) config('services.xendit.invoice_duration', 7200);
        if ($invoiceDuration < 300) {
            $invoiceDuration = 7200;
        }

        $externalId = 'order-' . $order->id;

        $frontendUrl = rtrim((string) config('services.xendit.frontend_url', config('app.frontend_url', 'http://localhost:5173')), '/');

        $params = [
            'external_id'      => $externalId,
            'amount'           => max(1, (int) round((float) ($order->gross_amount ?? $order->total ?? 0))),
            'payer_email'      => (string) ($order->user?->email ?? 'guest@email.com'),
            'description'      => 'Order #' . ($order->order_code ?? $order->id),
            'invoice_duration' => $invoiceDuration,
            'success_redirect_url' => $frontendUrl . '/orders/' . $order->id . '?payment=success',
            'failure_redirect_url' => $frontendUrl . '/orders/' . $order->id . '?payment=failed',
        ];

        $paymentMethodsList = [
            'BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'CIMB', 'SAHABAT_SAMPOERNA',
            'ALFAMART', 'INDOMARET', 'OVO', 'DANA', 'SHOPEEPAY', 'LINKAJA', 'QRIS'
        ];

        $method = strtoupper($order->payment_method ?? '');
        if (in_array($method, $paymentMethodsList)) {
            $params['payment_methods'] = [$method];
        }

        $response = Http::withBasicAuth((string) config('services.xendit.secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/') . '/v2/invoices', $params);

        if (!$response->successful()) {
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
            'amount'            => $order->gross_amount ?? $order->total ?? 0,
            'status'            => 'pending',
            'raw_response'      => $invoice,
        ]);
    }
}
