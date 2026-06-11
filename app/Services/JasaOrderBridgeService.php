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

        // Map mekanisme_pemesanan (legacy) to order_type (new)
        // Flow baru tidak menggunakan mekanisme_pemesanan lagi
        $orderType = $this->mapMekanismeToOrderType(
            $serviceOrder->mekanisme_pemesanan ?? $attributes['mekanisme_pemesanan'] ?? null
        );

        // Create order with order_type (PRIMARY) - NOT jasa_id
        // jasa_id disimpan di jasa_order_items, bukan di orders
        $order = Order::create([
            'user_id' => $serviceOrder->customer_id,
            'merchant_id' => $serviceOrder->merchant_id,
            'order_type' => $orderType, // consultation | direct_checkout | booking
            'total_price' => $serviceOrder->total_price,
            'payment_method' => $attributes['payment_method'] ?? $serviceOrder->payment_method ?? 'COD',
            'payment_status' => $attributes['payment_status'] ?? 'PENDING',
            'status' => $attributes['status'] ?? 'pending',
            // NOTE: mekanisme_pemesanan TIDAK disimpan ke orders table
        ]);

        // Create jasa_order_items with jasa_id from serviceOrder
        // service_type_booking stores the booking mechanism type
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
            'service_type_booking' => $orderType, // Store order_type as booking type
            'note' => $attributes['note'] ?? $serviceOrder->booking_note ?? null,
            'booking_note' => $serviceOrder->booking_note ?? null,
            'service_location_address' => $serviceOrder->service_location_address ?? null,
            'customer_latitude' => $serviceOrder->customer_latitude,
            'customer_longitude' => $serviceOrder->customer_longitude,
        ]);

        return $order;
    }

    /**
     * Map legacy mekanisme_pemesanan to order_type
     *
     * Legacy values: konsultasi, booking, keranjang
     * New values: consultation, direct_checkout, booking
     */
    private function mapMekanismeToOrderType(?string $mekanisme): string
    {
        if (!$mekanisme) {
            return 'direct_checkout'; // Default
        }

        $mapping = [
            'konsultasi' => 'consultation',
            'booking' => 'booking',
            'keranjang' => 'direct_checkout',
            // Aliases
            'langsung_pesan' => 'direct_checkout',
            'direct_checkout' => 'direct_checkout',
            'consultation' => 'consultation',
        ];

        return $mapping[strtolower($mekanisme)] ?? 'direct_checkout';
    }
}