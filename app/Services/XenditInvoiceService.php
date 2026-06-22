<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * XenditInvoiceService
 *
 * Service untuk membuat dan mengelola invoice Xendit.
 * HANYA mendukung Order (produk/kuliner dan jasa).
 *
 * NOTE: ServiceOrder sudah deprecated, gunakan Order + JasaOrderItem sebagai gantinya.
 */
class XenditInvoiceService
{
    /**
     * Base URL untuk Xendit API
     */
    protected string $baseUrl;

    /**
     * Secret key untuk Xendit
     */
    protected string $secretKey;

    /**
     * Callback token untuk validasi webhook
     */
    protected string $callbackToken;

    public function __construct()
    {
        $this->secretKey = (string) config('services.xendit.secret_key', '');
        $this->baseUrl = rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/');
        $this->callbackToken = (string) config('services.xendit.callback_token', '');
    }

    /**
     * Create atau get existing pending invoice untuk sebuah order.
     * Idempotent - jika invoice sudah ada dan pending, return yang ada.
     *
     * @param Order $order
     * @return Payment
     */
    public function createOrGetPendingInvoice(Order $order): Payment
    {
        // Generate external ID
        $externalId = $this->generateExternalId($order);

        // Cek apakah sudah ada payment pending untuk order ini
        $existingPayment = $this->findExistingPendingPayment($order, $externalId);
        if ($existingPayment && $existingPayment->xendit_invoice_id) {
            Log::info('[XenditInvoiceService] Reusing existing invoice', [
                'payment_id' => $existingPayment->id,
                'external_id' => $externalId,
            ]);
            return $existingPayment;
        }

        // Buat invoice baru
        return $this->createInvoice($order, $externalId);
    }

    /**
     * Generate external ID untuk order.
     * Format: order-{id}
     *
     * @param Order $order
     * @return string
     */
    protected function generateExternalId(Order $order): string
    {
        return 'order-' . $order->id;
    }

    /**
     * Find existing pending payment untuk order.
     *
     * @param Order $order
     * @param string $externalId
     * @return Payment|null
     */
    protected function findExistingPendingPayment(Order $order, string $externalId): ?Payment
    {
        return Payment::where('order_id', $order->id)
            ->where('status', 'pending')
            ->first();
    }

    /**
     * Create invoice baru di Xendit.
     *
     * @param Order $order
     * @param string $externalId
     * @return Payment
     */
    protected function createInvoice(Order $order, string $externalId): Payment
    {
        $amount = $this->getOrderAmount($order);
        $description = $this->getOrderDescription($order);
        $customer = $this->getOrderCustomer($order);
        $successUrl = $this->getSuccessUrl($order);
        $failureUrl = $this->getFailureUrl($order);

        $payload = [
            'external_id' => $externalId,
            'amount' => $amount,
            'description' => $description,
            'currency' => 'IDR',
            'invoice_duration' => $this->getInvoiceDuration(),
            'success_redirect_url' => $successUrl,
            'failure_redirect_url' => $failureUrl,
        ];

        // Add customer info if available
        if (!empty($customer['email'])) {
            $payload['customer'] = [
                'email' => $customer['email'],
                'given_name' => $customer['name'] ?? 'Customer',
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
            Log::error('[XenditInvoiceService] Xendit API error', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \Exception('Failed to create Xendit invoice: ' . $response->body());
        }

        $invoiceData = $response->json();

        // Parse expiry datetime
        $expiredAt = null;
        if (!empty($invoiceData['expiry_date'])) {
            $expiredAt = \Carbon\Carbon::parse($invoiceData['expiry_date']);
        }

        // Create or update payment record
        $payment = $this->createPaymentRecord($order, $externalId, $invoiceData, $expiredAt);

        Log::info('[XenditInvoiceService] Invoice created successfully', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoiceData['id'],
            'invoice_url' => $invoiceData['invoice_url'] ?? null,
        ]);

        return $payment;
    }

    /**
     * Create payment record di database.
     *
     * @param Order $order
     * @param string $externalId
     * @param array $invoiceData
     * @param \Carbon\Carbon|null $expiredAt
     * @return Payment
     */
    protected function createPaymentRecord(
        Order $order,
        string $externalId,
        array $invoiceData,
        ?\Carbon\Carbon $expiredAt = null
    ): Payment {
        return Payment::updateOrCreate(
            [
                'external_id' => $externalId,
            ],
            [
                'order_id' => $order->id,
                'xendit_invoice_id' => $invoiceData['id'] ?? null,
                'invoice_url' => $invoiceData['invoice_url'] ?? null,
                'status' => 'pending',
                'amount' => $invoiceData['amount'] ?? 0,
                'expired_at' => $expiredAt,
            ]
        );
    }

    /**
     * Get order amount untuk invoice.
     *
     * @param Order $order
     * @return int
     */
    protected function getOrderAmount(Order $order): int
    {
        // Prioritas: total_payment_snapshot (subtotal + platform_fee) > total_price
        // total_payment_snapshot selalu di-set saat order dibuat
        return (int) ($order->total_payment_snapshot ?? $order->total_price ?? 0);
    }

    /**
     * Get order description untuk invoice.
     *
     * @param Order $order
     * @return string
     */
    protected function getOrderDescription(Order $order): string
    {
        // Cek tipe order
        if ($order->order_type === 'jasa') {
            // Untuk jasa - gunakan jasa_order_items
            $order->loadMissing('jasaItems.jasa');
            $jasaItem = $order->jasaItems->first();
            $serviceName = $jasaItem?->jasa?->title ?? 'Layanan Jasa';
            return "Pembayaran Layanan: {$serviceName}";
        }

        // Untuk produk/kuliner - gunakan product_order_items
        $order->loadMissing('items');
        $items = $order->items ?? collect();
        $itemCount = $items->count();

        if ($itemCount > 0) {
            $names = $items->take(3)->map(fn($item) => $item->product_name_snapshot ?? 'Produk')->implode(', ');
            $remaining = $itemCount - 3;
            if ($remaining > 0) {
                return "Pembayaran {$names} dan {$remaining} item lainnya";
            }
            return "Pembayaran {$names}";
        }

        return 'Pembayaran Pesanan';
    }

    /**
     * Get customer info untuk invoice.
     *
     * @param Order $order
     * @return array
     */
    protected function getOrderCustomer(Order $order): array
    {
        $user = $order->user;
        return [
            'email' => $user?->email,
            'name' => $order->nama ?? $user?->name ?? 'Customer',
        ];
    }

    /**
     * Get success redirect URL.
     *
     * @return string
     */
    protected function getSuccessUrl(?\App\Models\Order $order = null): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        // Jasa orders redirect to service history
        if ($order && $order->order_type === 'jasa') {
            return $base . '/jasa-history';
        }
        return $base . '/orders';
    }

    /**
     * Get failure redirect URL.
     *
     * @return string
     */
    protected function getFailureUrl(?\App\Models\Order $order = null): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        // Jasa orders redirect to service history
        if ($order && $order->order_type === 'jasa') {
            return $base . '/jasa-history';
        }
        return $base . '/orders';
    }

    /**
     * Get invoice duration dalam detik (default 24 jam).
     *
     * @return int
     */
    protected function getInvoiceDuration(): int
    {
        $hours = (int) config('app.xendit_invoice_duration_hours', 24);
        return $hours * 3600;
    }

    /**
     * Get invoice status dari Xendit.
     *
     * @param string $invoiceId
     * @return array|null
     */
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

    /**
     * Validate callback token dari webhook.
     *
     * @param string|null $token
     * @return bool
     */
    public function validateCallbackToken(?string $token): bool
    {
        if (empty($this->callbackToken)) {
            return false;
        }
        return hash_equals($this->callbackToken, (string) $token);
    }
}
