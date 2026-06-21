<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WebPushService
 *
 * Service untuk mengirim push notification ke frontend.
 * NON-BLOCKING: failure akan di-log tapi tidak mengganggu alur utama.
 */
class WebPushService
{
    /**
     * Send payment status update notification.
     * Ini dipanggil dari webhook saat payment status berubah.
     *
     * @param Order $order
     * @param Payment|null $payment
     * @param string|null $context Context notification (e.g., 'webhook', 'manual')
     * @return bool
     */
    public function sendPaymentStatusUpdate(Order $order, ?Payment $payment = null, ?string $context = null): bool
    {
        try {
            $payload = $this->buildPaymentPayload($order, $payment, $context);

            // Log untuk debugging
            Log::info('[WebPushService] Sending payment status update', [
                'order_id' => $order->id,
                'payment_id' => $payment?->id,
                'context' => $context,
            ]);

            // Jika ada FCM/API endpoint untuk push notification, implementasikan di sini
            // Untuk saat ini, kita cukup log karena FE biasanya pakai polling/websocket

            return true;
        } catch (\Throwable $e) {
            // NON-BLOCKING - log error tapi jangan throw
            Log::warning('[WebPushService] Failed to send payment status update', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send order status update notification.
     * Dipanggil saat status order berubah.
     *
     * @param Order $order
     * @param string|null $cancelContext Context jika order dibatalkan
     * @return bool
     */
    public function sendOrderStatusUpdate(Order $order, ?string $cancelContext = null): bool
    {
        try {
            $payload = $this->buildOrderPayload($order, $cancelContext);

            Log::info('[WebPushService] Sending order status update', [
                'order_id' => $order->id,
                'status' => $order->status,
                'cancel_context' => $cancelContext,
            ]);

            // Implementasi push notification di sini jika diperlukan
            // Untuk saat ini, FE akan menerima update via WebSocket/Broadcast events

            return true;
        } catch (\Throwable $e) {
            Log::warning('[WebPushService] Failed to send order status update', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Notify merchant tentang order baru.
     *
     * @param Order $order
     * @return bool
     */
    public function notifyOrderCreated(Order $order): bool
    {
        try {
            Log::info('[WebPushService] Notifying order created', [
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[WebPushService] Failed to notify order created', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build payload untuk payment status notification.
     *
     * @param Order $order
     * @param Payment|null $payment
     * @param string|null $context
     * @return array
     */
    protected function buildPaymentPayload(Order $order, ?Payment $payment, ?string $context): array
    {
        return [
            'type' => 'payment_status_updated',
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            'payment_status' => $payment?->status ?? $order->payment_status,
            'payment_method' => $payment?->payment_method,
            'paid_at' => $payment?->paid_at?->toISOString(),
            'context' => $context,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Build payload untuk order status notification.
     *
     * @param Order $order
     * @param string|null $cancelContext
     * @return array
     */
    protected function buildOrderPayload(Order $order, ?string $cancelContext): array
    {
        return [
            'type' => 'order_status_updated',
            'order_id' => $order->id,
            'status' => $order->status,
            'user_id' => $order->user_id,
            'merchant_id' => $order->merchant_id,
            'cancel_context' => $cancelContext,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Send notification via FCM (Firebase Cloud Messaging).
     * Ini adalah placeholder untuk implementasi FCM jika diperlukan.
     *
     * @param string $fcmToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return bool
     */
    public function sendViaFcm(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            $serverKey = config('services.fcm.server_key');

            if (empty($serverKey)) {
                Log::debug('[WebPushService] FCM server key not configured');
                return false;
            }

            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[WebPushService] FCM send failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
