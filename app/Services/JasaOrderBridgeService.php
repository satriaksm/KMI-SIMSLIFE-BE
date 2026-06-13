<?php

namespace App\Services;

use App\Models\JasaOrderItem;
use App\Models\Order;
use App\Models\ServiceConsultation;
use App\Models\ServiceOrder;

class JasaOrderBridgeService
{
    /**
     * Create linked order from service_order (legacy migration helper)
     *
     * Maps service_orders data to unified orders + jasa_order_items structure.
     *
     * @param ServiceOrder $serviceOrder The source service order
     * @param array $attributes Additional attributes (optional overrides)
     * @param ServiceConsultation|null $consultation Related consultation (optional)
     * @return Order
     */
    public function createLinkedOrder(ServiceOrder $serviceOrder, array $attributes = [], ?ServiceConsultation $consultation = null): Order
    {
        $bookingDate = $attributes['tanggal']
            ?? ($serviceOrder->booking_date?->toDateString() ?? null);
        $bookingTime = $attributes['waktu']
            ?? ($serviceOrder->booking_time?->format('H:i') ?? null);

        // Map mekanisme_pemesanan (legacy) to order_method
        // order_method values: direct, scheduled, consultation
        $orderMethod = $this->mapMekanismeToOrderMethod(
            $serviceOrder->mekanisme_pemesanan ?? $attributes['mekanisme_pemesanan'] ?? null
        );

        // Create order with order_type = 'jasa' (PRIMARY - jenis order)
        // jasa_id disimpan di jasa_order_items, bukan di orders
        // order_method disimpan di jasa_order_items, bukan di orders
        $order = Order::create([
            'user_id' => $serviceOrder->customer_id,
            'merchant_id' => $serviceOrder->merchant_id,
            'order_type' => 'jasa', // PRIMARY: jenis order (jasa/product)
            'total_price' => $serviceOrder->total_price,
            'payment_method' => $attributes['payment_method'] ?? $serviceOrder->payment_method ?? 'COD',
            'payment_status' => $attributes['payment_status'] ?? 'PENDING',
            'status' => $attributes['status'] ?? 'pending',
        ]);

        // Create jasa_order_items with order_method
        $jasaOrderItem = JasaOrderItem::create([
            'order_id' => $order->id,
            'jasa_id' => $serviceOrder->jasa_id,
            'service_order_id' => $serviceOrder->id,
            'service_consultation_id' => $consultation?->id,
            'quantity' => 1,
            'price' => $serviceOrder->total_price,
            'subtotal' => $serviceOrder->total_price,
            'booking_date' => $bookingDate,
            'booking_time' => $bookingTime,
            'service_type' => $serviceOrder->service_type ?? $attributes['service_type'] ?? null,
            'service_type_booking' => $orderMethod, // Legacy - for backward compat
            'order_method' => $orderMethod, // PRIMARY
            'note' => $attributes['note'] ?? $serviceOrder->booking_note ?? null,
            'booking_note' => $serviceOrder->booking_note ?? null,
            'service_location_address' => $serviceOrder->service_location_address ?? null,
            'customer_latitude' => $serviceOrder->customer_latitude,
            'customer_longitude' => $serviceOrder->customer_longitude,
        ]);

        return $order;
    }

    /**
     * Map legacy mekanisme_pemesanan to order_method
     *
     * Legacy values: konsultasi, booking, keranjang
     * New values: consultation, scheduled, direct
     */
    private function mapMekanismeToOrderMethod(?string $mekanisme): string
    {
        if (!$mekanisme) {
            return 'direct'; // Default
        }

        $mapping = [
            'konsultasi' => 'consultation',
            'booking' => 'scheduled',
            'keranjang' => 'direct',
            // Aliases
            'langsung_pesan' => 'direct',
            'direct_checkout' => 'direct',
            'consultation' => 'consultation',
            'scheduled' => 'scheduled',
            'direct' => 'direct',
        ];

        return $mapping[strtolower($mekanisme)] ?? 'direct';
    }
}