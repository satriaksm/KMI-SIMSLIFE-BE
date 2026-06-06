<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class XenditWebhookController extends Controller
{
    /**
     * Handle Xendit payment callback/webhook.
     * Endpoint: POST /api/payment/xendit/webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleCallback(Request $request)
    {
        // Log semua payload yang diterima
        Log::info('Xendit Webhook Received', $request->all());

        // Validasi header X-CALLBACK-TOKEN
        $expectedToken = config('services.xendit.callback_token');
        $receivedToken = $request->header('x-callback-token');

        if (!$expectedToken || $receivedToken !== $expectedToken) {
            Log::warning('Xendit Webhook: Invalid callback token', [
                'ip' => $request->ip(),
                'expected' => $expectedToken ? 'set' : 'not_set',
                'received' => $receivedToken,
            ]);
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $externalId = $request->input('external_id');
        $status = $request->input('status');
        $invoiceId = $request->input('id'); // Xendit invoice ID

        Log::info('Xendit Webhook Parsed', [
            'external_id' => $externalId,
            'status' => $status,
            'invoice_id' => $invoiceId,
        ]);

        // Cek apakah ini PAID
        if ($status === 'PAID') {
            $this->handlePaymentSuccess($externalId, $status, $invoiceId, $request->all());
        }
        // Cek EXPIRED
        elseif ($status === 'EXPIRED') {
            $this->handlePaymentExpired($externalId, $invoiceId);
        }
        // Status lain di-log saja
        else {
            Log::info('Xendit Webhook: Unhandled status', [
                'external_id' => $externalId,
                'status' => $status,
            ]);
        }

        // Xendit butuh response 200 agar tidak retry
        return response()->json(['success' => true], 200);
    }

    /**
     * Handle pembayaran berhasil (PAID).
     */
    private function handlePaymentSuccess(string $externalId, string $status, ?string $invoiceId, array $payload)
    {
        Log::info('Xendit Payment Success', [
            'invoice_id' => $invoiceId,
            'external_id' => $externalId,
            'status' => $status,
        ]);

        // Cek format external_id:
        // "service_order_{id}" → ServiceOrder (Jasa)
        // "order_{id}" → Order (Toko/Kuliner)
        // "invoice_{id}" → cari via xendit_invoice_id

        // 1. Coba cari sebagai ServiceOrder
        if (str_starts_with($externalId, 'service_order_')) {
            $orderId = (int) str_replace('service_order_', '', $externalId);
            $this->updateServiceOrderPaid($orderId, $invoiceId);
            return;
        }

        // 2. Coba cari sebagai Order (Toko/Kuliner)
        if (str_starts_with($externalId, 'order_')) {
            $orderId = (int) str_replace('order_', '', $externalId);
            $this->updateOrderPaid($orderId, $invoiceId);
            return;
        }

        // 3. Jika external_id adalah invoice ID langsung, cari di ServiceOrder
        if ($invoiceId) {
            $serviceOrder = \App\Models\ServiceOrder::where('xendit_invoice_id', $invoiceId)->first();
            if ($serviceOrder) {
                $this->updateServiceOrderPaid($serviceOrder->id, $invoiceId);
                return;
            }

            $order = \App\Models\Order::where('payment_reference', $invoiceId)->first();
            if ($order) {
                $this->updateOrderPaid($order->id, $invoiceId);
                return;
            }
        }

        Log::warning('Xendit Webhook: Order not found', [
            'external_id' => $externalId,
            'invoice_id' => $invoiceId,
        ]);
    }

    /**
     * Handle pembayaran expired (EXPIRED).
     */
    private function handlePaymentExpired(string $externalId, ?string $invoiceId)
    {
        Log::info('Xendit Payment Expired', [
            'external_id' => $externalId,
            'invoice_id' => $invoiceId,
        ]);

        if (str_starts_with($externalId, 'service_order_')) {
            $orderId = (int) str_replace('service_order_', '', $externalId);
            $order = \App\Models\ServiceOrder::find($orderId);
            if ($order) {
                $order->payment_status = 'UNPAID';
                $order->save();
                Log::info('Xendit: Service order expired', ['order_id' => $orderId]);
            }
        }
    }

    /**
     * Update ServiceOrder menjadi PAID.
     */
    private function updateServiceOrderPaid(int $orderId, ?string $invoiceId)
    {
        try {
            $order = \App\Models\ServiceOrder::find($orderId);
            if (!$order) {
                Log::warning('Xendit: ServiceOrder not found', ['order_id' => $orderId]);
                return;
            }

            // Cegah double update
            if ($order->payment_status === 'PAID') {
                Log::info('Xendit: ServiceOrder already paid', ['order_id' => $orderId]);
                return;
            }

            $order->payment_status = 'PAID';
            $order->paid_at = now();
            if ($invoiceId) {
                $order->xendit_invoice_id = $invoiceId;
                $order->payment_reference = $invoiceId;
            }
            $order->save();

            Log::info('Xendit: ServiceOrder updated to PAID', [
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'payment_status' => $order->payment_status,
            ]);
        } catch (\Exception $e) {
            Log::error('Xendit: Failed to update ServiceOrder', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update Order (Toko/Kuliner) menjadi PAID.
     */
    private function updateOrderPaid(int $orderId, ?string $invoiceId)
    {
        try {
            $order = \App\Models\Order::find($orderId);
            if (!$order) {
                Log::warning('Xendit: Order not found', ['order_id' => $orderId]);
                return;
            }

            // Cegah double update
            if ($order->payment_status === 'PAID') {
                Log::info('Xendit: Order already paid', ['order_id' => $orderId]);
                return;
            }

            $order->payment_status = 'PAID';
            $order->paid_at = now();
            if ($invoiceId) {
                $order->payment_reference = $invoiceId;
            }
            $order->save();

            Log::info('Xendit: Order updated to PAID', [
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
            ]);
        } catch (\Exception $e) {
            Log::error('Xendit: Failed to update Order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buat invoice Xendit (static method, dipanggil dari controller lain).
     *
     * @param string $externalId Format: "service_order_{id}" atau "order_{id}"
     * @param int $amount
     * @param string $description
     * @param array $customer ['email' => '', 'name' => '']
     * @return array|null
     */
    public static function createInvoice(string $externalId, int $amount, string $description, array $customer = [])
    {
        $secretKey = config('services.xendit.secret_key');

        if (!$secretKey) {
            Log::error('Xendit: Secret key not configured');
            return null;
        }

        try {
            // Use FRONTEND_URL for redirect URLs (customer-facing pages)
            // APP_URL is for backend/webhook only (ngrok)
            $frontendUrl = config('app.frontend_url', config('app.url'));
            $isServiceOrder = str_starts_with($externalId, 'service_order_');

            // Determine the success page based on order type
            // For service orders, redirect to booking confirmation page first (shows order details)
            // Then user can go to service history from there
            if ($isServiceOrder) {
                // Extract order ID from external_id (e.g., "service_order_123" -> "123")
                $orderId = (int) str_replace('service_order_', '', $externalId);
                $successUrl = rtrim($frontendUrl, '/') . "/booking-confirmation?order_id={$orderId}";
                $failureUrl = rtrim($frontendUrl, '/') . '/pembayaran-jasa?payment=failed';
            } else {
                // Product order -> order history
                $successUrl = rtrim($frontendUrl, '/') . '/orders';
                $failureUrl = rtrim($frontendUrl, '/') . '/pembayaran-product?payment=failed';
            }

            $payload = [
                'external_id' => $externalId,
                'amount' => $amount,
                'description' => $description,
                'currency' => 'IDR',
                'invoice_duration' => 86400,
                'success_redirect_url' => $successUrl,
                'failure_redirect_url' => $failureUrl,
            ];

            if (!empty($customer['email'])) {
                $payload['customer'] = [
                    'email' => $customer['email'],
                    'given_name' => $customer['name'] ?? 'Customer',
                ];
            }

            Log::info('Xendit: Creating invoice with redirect URLs', [
                'external_id' => $externalId,
                'success_redirect_url' => $successUrl,
                'failure_redirect_url' => $failureUrl,
            ]);

            $response = Http::withBasicAuth($secretKey, '')
                ->post('https://api.xendit.co/v2/invoices', $payload);

            $data = $response->json();

            if ($response->successful()) {
                Log::info('Xendit Invoice Created', [
                    'external_id' => $externalId,
                    'invoice_id' => $data['id'] ?? null,
                    'invoice_url' => $data['invoice_url'] ?? null,
                    'success_redirect_url' => $data['success_redirect_url'] ?? null,
                ]);
                return $data;
            }

            Log::error('Xendit: Failed to create invoice', [
                'external_id' => $externalId,
                'response' => $data,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Xendit: Exception creating invoice', [
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
