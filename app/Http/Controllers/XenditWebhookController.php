<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * XenditWebhookController
 *
 * Centralized Xendit webhook handler for all order types.
 * Handles callbacks from Xendit for:
 * - Product orders (order_{id})
 * - Service/Jasa orders (order_{id})
 * - Legacy ServiceOrders (service_order_{id})
 *
 * External ID format:
 * - order_{id} = all new orders (product/jasa)
 * - service_order_{id} = legacy service orders
 * - jasa_{id} = legacy jasa orders (kept for backward compat)
 */
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
        // Extract payment channel from Xendit payload
        $paymentChannel = $payload['payment_channel'] ?? $payload['payment_method'] ?? null;

        Log::info('Xendit Payment Success', [
            'invoice_id' => $invoiceId,
            'external_id' => $externalId,
            'status' => $status,
            'payment_channel' => $paymentChannel,
        ]);

        // Cek format external_id:
        // "order_{id}" → Order (Toko/Kuliner atau Jasa) - XenditWebhookController auto-detects via order_type

        // 1. Coba cari sebagai Order via order_{id} format
        if (str_starts_with($externalId, 'order_')) {
            $orderId = (int) str_replace('order_', '', $externalId);
            $this->updateOrderPaid($orderId, $invoiceId, $paymentChannel);
            return;
        }

        // 2. Coba cari sebagai ServiceOrder (legacy)
        if (str_starts_with($externalId, 'service_order_')) {
            $orderId = (int) str_replace('service_order_', '', $externalId);
            $this->updateServiceOrderPaid($orderId, $invoiceId, $paymentChannel);
            return;
        }

        // 3. Jika external_id adalah invoice ID langsung, cari di ServiceOrder
        if ($invoiceId) {
            $serviceOrder = \App\Models\ServiceOrder::where('xendit_invoice_id', $invoiceId)->first();
            if ($serviceOrder) {
                $this->updateServiceOrderPaid($serviceOrder->id, $invoiceId, $paymentChannel);
                return;
            }

            $order = \App\Models\Order::where('payment_reference', $invoiceId)->first();
            if ($order) {
                $this->updateOrderPaid($order->id, $invoiceId, $paymentChannel);
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

        // order_{id} → Order (Toko/Kuliner atau Jasa)
        if (str_starts_with($externalId, 'order_')) {
            $orderId = (int) str_replace('order_', '', $externalId);
            $order = \App\Models\Order::find($orderId);
            if ($order) {
                $order->payment_status = 'UNPAID';
                $order->save();
                Log::info('Xendit: Order expired', ['order_id' => $orderId]);
            }
            return;
        }

        // service_order_{id} → ServiceOrder (legacy)
        if (str_starts_with($externalId, 'service_order_')) {
            $orderId = (int) str_replace('service_order_', '', $externalId);
            $order = \App\Models\ServiceOrder::find($orderId);
            if ($order) {
                $order->payment_status = 'UNPAID';
                $order->save();
                Log::info('Xendit: Service order expired', ['order_id' => $orderId]);
            }
            return;
        }

        // jasa_{id} → Order (Jasa legacy)
        if (str_starts_with($externalId, 'jasa_')) {
            $orderId = (int) str_replace('jasa_', '', $externalId);
            $order = \App\Models\Order::find($orderId);
            if ($order) {
                $order->payment_status = 'UNPAID';
                $order->save();
                Log::info('Xendit: Jasa order expired', ['order_id' => $orderId]);
            }
        }
    }

    /**
     * Update ServiceOrder menjadi PAID.
     * Also updates the related orders table entry.
     */
    private function updateServiceOrderPaid(int $orderId, ?string $invoiceId, ?string $paymentChannel = null)
    {
        try {
            $serviceOrder = \App\Models\ServiceOrder::find($orderId);
            if (!$serviceOrder) {
                Log::warning('Xendit: ServiceOrder not found', ['order_id' => $orderId]);
                return;
            }

            // Cegah double update
            if ($serviceOrder->payment_status === 'PAID') {
                Log::info('Xendit: ServiceOrder already paid', ['order_id' => $orderId]);
                return;
            }

            // Update service_orders table
            $serviceOrder->payment_status = 'PAID';
            $serviceOrder->paid_at = now();
            if ($invoiceId) {
                $serviceOrder->xendit_invoice_id = $invoiceId;
                $serviceOrder->payment_reference = $invoiceId;
            }
            // Simpan payment channel aktual dari Xendit
            if ($paymentChannel) {
                $serviceOrder->payment_channel = $paymentChannel;
                $serviceOrder->paid_channel = $paymentChannel;
            }
            $serviceOrder->save();

            // Also update the related orders table
            $jasaOrderItem = $serviceOrder->jasaOrderItems()->first();
            if ($jasaOrderItem && $jasaOrderItem->order_id) {
                $order = \App\Models\Order::find($jasaOrderItem->order_id);
                if ($order) {
                    $order->payment_status = 'PAID';
                    $order->paid_at = now();
                    if ($invoiceId) {
                        $order->payment_reference = $invoiceId;
                    }
                    if ($paymentChannel) {
                        $order->payment_channel = $paymentChannel;
                        $order->paid_channel = $paymentChannel;
                    }
                    $order->save();

                    Log::info('Xendit: Order (jasa) updated to PAID', [
                        'order_id' => $order->id,
                        'service_order_id' => $orderId,
                        'invoice_id' => $invoiceId,
                        'payment_channel' => $paymentChannel,
                    ]);
                }
            }

            Log::info('Xendit: ServiceOrder updated to PAID', [
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'payment_channel' => $paymentChannel,
                'payment_status' => $serviceOrder->payment_status,
            ]);
        } catch (\Exception $e) {
            Log::error('Xendit: Failed to update ServiceOrder', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update Order (Jasa) menjadi PAID via webhook.
     * Also syncs to related service_orders for backward compatibility.
     */
    private function updateJasaOrderPaid(int $orderId, ?string $invoiceId, ?string $paymentChannel = null)
    {
        try {
            $order = \App\Models\Order::find($orderId);
            if (!$order) {
                Log::warning('Xendit: Jasa Order not found', ['order_id' => $orderId]);
                return;
            }

            // Cegah double update
            if ($order->payment_status === 'PAID') {
                Log::info('Xendit: Jasa Order already paid', ['order_id' => $orderId]);
                return;
            }

            // Update orders table (primary)
            $order->payment_status = 'PAID';
            $order->paid_at = now();
            if ($invoiceId) {
                $order->payment_reference = $invoiceId;
            }
            if ($paymentChannel) {
                $order->payment_channel = $paymentChannel;
                $order->paid_channel = $paymentChannel;
            }
            $order->save();

            // Also update related service_orders for backward compatibility
            $jasaOrderItems = $order->jasaOrderItems;
            foreach ($jasaOrderItems as $jasaOrderItem) {
                if ($jasaOrderItem->service_order_id) {
                    $serviceOrder = \App\Models\ServiceOrder::find($jasaOrderItem->service_order_id);
                    if ($serviceOrder && $serviceOrder->payment_status !== 'PAID') {
                        $serviceOrder->payment_status = 'PAID';
                        $serviceOrder->paid_at = now();
                        if ($invoiceId) {
                            $serviceOrder->xendit_invoice_id = $invoiceId;
                            $serviceOrder->payment_reference = $invoiceId;
                        }
                        if ($paymentChannel) {
                            $serviceOrder->payment_channel = $paymentChannel;
                            $serviceOrder->paid_channel = $paymentChannel;
                        }
                        $serviceOrder->save();

                        Log::info('Xendit: ServiceOrder synced to PAID from Order webhook', [
                            'order_id' => $orderId,
                            'service_order_id' => $serviceOrder->id,
                        ]);
                    }
                }
            }

            Log::info('Xendit: Jasa Order updated to PAID', [
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'payment_channel' => $paymentChannel,
                'payment_status' => $order->payment_status,
            ]);
        } catch (\Exception $e) {
            Log::error('Xendit: Failed to update Jasa Order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update Order (Toko/Kuliner atau Jasa) menjadi PAID.
     * Auto-detects order type and syncs to related tables.
     */
    private function updateOrderPaid(int $orderId, ?string $invoiceId, ?string $paymentChannel = null)
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
            // Simpan payment channel aktual dari Xendit
            if ($paymentChannel) {
                $order->payment_channel = $paymentChannel;
                $order->paid_channel = $paymentChannel;
            }
            $order->save();

            // Sync to related tables based on order_type
            if ($order->order_type === 'jasa') {
                // Sync to service_orders for backward compatibility
                $jasaOrderItems = $order->jasaOrderItems;
                foreach ($jasaOrderItems as $jasaOrderItem) {
                    if ($jasaOrderItem->service_order_id) {
                        $serviceOrder = \App\Models\ServiceOrder::find($jasaOrderItem->service_order_id);
                        if ($serviceOrder && $serviceOrder->payment_status !== 'PAID') {
                            $serviceOrder->payment_status = 'PAID';
                            $serviceOrder->paid_at = now();
                            if ($invoiceId) {
                                $serviceOrder->xendit_invoice_id = $invoiceId;
                                $serviceOrder->payment_reference = $invoiceId;
                            }
                            if ($paymentChannel) {
                                $serviceOrder->payment_channel = $paymentChannel;
                                $serviceOrder->paid_channel = $paymentChannel;
                            }
                            $serviceOrder->save();

                            Log::info('Xendit: ServiceOrder synced to PAID', [
                                'order_id' => $orderId,
                                'service_order_id' => $serviceOrder->id,
                            ]);
                        }
                    }
                }

                Log::info('Xendit: Jasa Order updated to PAID', [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'payment_channel' => $paymentChannel,
                ]);
            } else {
                // Product order - no additional sync needed
                Log::info('Xendit: Product Order updated to PAID', [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'payment_channel' => $paymentChannel,
                ]);
            }
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
     * @param string $externalId Format: "order_{id}" (semua jenis order)
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

            // Determine success/failure redirect URLs based on order type
            // All orders use order_{id} format, redirect based on order_type
            $isServiceOrder = str_starts_with($externalId, 'service_order_');

            if ($isServiceOrder) {
                // Legacy service order -> booking confirmation
                $orderId = (int) preg_replace('/[^0-9]/', '', $externalId);
                $successUrl = rtrim($frontendUrl, '/') . "/booking-confirmation?order_id={$orderId}";
                $failureUrl = rtrim($frontendUrl, '/') . '/pembayaran-jasa?payment=failed';
            } else {
                // Standard order -> order history (auto-detects product vs jasa in frontend)
                $successUrl = rtrim($frontendUrl, '/') . '/orders';
                $failureUrl = rtrim($frontendUrl, '/') . '/orders';
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

    /**
     * Get invoice status from Xendit API.
     * Used as fallback when webhook hasn't been received yet.
     *
     * @param string $invoiceId Xendit invoice ID
     * @return array|null Invoice data or null on error
     */
    public static function getInvoiceStatus(string $invoiceId): ?array
    {
        $secretKey = config('services.xendit.secret_key');

        if (!$secretKey) {
            Log::error('Xendit: Secret key not configured');
            return null;
        }

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->get("https://api.xendit.co/v2/invoices/{$invoiceId}");

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Xendit: Invoice status fetched', [
                    'invoice_id' => $invoiceId,
                    'status' => $data['status'] ?? null,
                    'payment_channel' => $data['payment_channel'] ?? null,
                ]);
                return $data;
            }

            Log::error('Xendit: Failed to get invoice status', [
                'invoice_id' => $invoiceId,
                'response' => $response->json(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Xendit: Exception getting invoice status', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Refresh payment status for an order.
     * Called by frontend when returning from Xendit to ensure latest status.
     * Accepts orders.id as primary ID.
     *
     * @param int $orderId orders.id (not service_orders.id)
     * @return array Result with status info
     */
    public function refreshPaymentStatus(int $orderId): array
    {
        try {
            // First try: look up as orders.id (primary)
            $order = \App\Models\Order::find($orderId);
            if (!$order) {
                return ['success' => false, 'message' => 'Order not found'];
            }

            // If already PAID, no need to refresh
            if ($order->payment_status === 'PAID') {
                return [
                    'success' => true,
                    'payment_status' => 'PAID',
                    'payment_channel' => $order->payment_channel,
                    'already_paid' => true,
                ];
            }

            // Try to find invoice_id from jasa_order_items → service_orders
            $jasaItem = \App\Models\JasaOrderItem::where('order_id', $orderId)->first();
            $invoiceId = $jasaItem?->serviceOrder?->xendit_invoice_id
                ?? $order->payment_reference;

            if (!$invoiceId) {
                return [
                    'success' => false,
                    'message' => 'No invoice ID found',
                    'payment_status' => $order->payment_status,
                ];
            }

            $invoiceData = self::getInvoiceStatus($invoiceId);
            if (!$invoiceData) {
                return [
                    'success' => false,
                    'message' => 'Failed to get invoice status from Xendit',
                    'payment_status' => $order->payment_status,
                ];
            }

            // Update based on Xendit response
            $xenditStatus = $invoiceData['status'] ?? null;
            $paymentChannel = $invoiceData['payment_channel'] ?? null;

            if ($xenditStatus === 'PAID' || $xenditStatus === 'SETTLED') {
                // Update orders table (primary)
                $order->payment_status = 'PAID';
                $order->paid_at = now();
                if ($paymentChannel) {
                    $order->payment_channel = $paymentChannel;
                    $order->paid_channel = $paymentChannel;
                }
                $order->save();

                // Sync to service_orders for backward compatibility
                if ($jasaItem?->service_order_id) {
                    $serviceOrder = \App\Models\ServiceOrder::find($jasaItem->service_order_id);
                    if ($serviceOrder) {
                        $serviceOrder->payment_status = 'PAID';
                        $serviceOrder->paid_at = now();
                        $serviceOrder->payment_channel = $paymentChannel;
                        $serviceOrder->paid_channel = $paymentChannel;
                        if ($invoiceId) {
                            $serviceOrder->xendit_invoice_id = $invoiceId;
                            $serviceOrder->payment_reference = $invoiceId;
                        }
                        $serviceOrder->save();
                    }
                }

                Log::info('Xendit: Payment status refreshed from Xendit API', [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId,
                    'payment_channel' => $paymentChannel,
                ]);

                return [
                    'success' => true,
                    'payment_status' => 'PAID',
                    'payment_channel' => $paymentChannel,
                    'refreshed' => true,
                ];
            }

            // Invoice not paid yet
            return [
                'success' => true,
                'payment_status' => $order->payment_status,
                'xendit_status' => $xenditStatus,
                'message' => 'Invoice not yet paid',
            ];
        } catch (\Exception $e) {
            Log::error('Xendit: Failed to refresh payment status', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
