<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;
use Xendit\Configuration;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Invoice\InvoiceApi;

class XenditInvoiceService
{
    public function __construct()
    {
        Configuration::setXenditKey((string) config('services.xendit.secret_key'));
    }

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

        $externalId = 'order-' . $order->id . '-' . Str::random(6);

        $params = [
            'external_id' => $externalId,
            'amount' => max(1, (int) round((float) $order->gross_amount)),
            'payer_email' => (string) ($order->user?->email ?? 'guest@email.com'),
            'description' => 'Order #' . $order->order_code,
            'invoice_duration' => $invoiceDuration,
        ];

        $invoiceApi = new InvoiceApi();
        $invoiceRequest = new CreateInvoiceRequest($params);
        $invoice = $invoiceApi->createInvoice($invoiceRequest);

        return Payment::query()->create([
            'order_id' => $order->id,
            'external_id' => $externalId,
            'xendit_invoice_id' => $invoice->getId(),
            'invoice_url' => $invoice->getInvoiceUrl(),
            'payment_method' => null,
            'expired_at' => now()->addSeconds($invoiceDuration),
            'amount' => $order->gross_amount,
            'status' => 'pending',
            'raw_response' => json_decode(json_encode($invoice), true),
        ]);
    }
}
