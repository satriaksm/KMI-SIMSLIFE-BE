<?php

namespace App\Services;

use App\Models\JasaOrderItem;
use App\Models\Order;
use App\Models\ServiceConsultation;
use App\Models\ServiceOrder;

class JasaOrderBridgeService
{
    public function createLinkedOrder(ServiceOrder $serviceOrder, array $attributes = [], ?ServiceConsultation $consultation = null): Order
    {
        $bookingDate = $attributes['tanggal']
            ?? ($serviceOrder->booking_date?->toDateString() ?? null);
        $bookingTime = $attributes['waktu']
            ?? ($serviceOrder->booking_time?->format('H:i') ?? null);

        $order = Order::create([
            'user_id' => $serviceOrder->customer_id,
            'merchant_id' => $serviceOrder->merchant_id,
            'jasa_id' => $serviceOrder->jasa_id,
            'order_type' => 'jasa',
            'nama' => $attributes['nama'] ?? ($serviceOrder->customer_name ?? 'Pelanggan'),
            'tel' => $attributes['tel'] ?? ($serviceOrder->customer_phone ?? '0000000000'),
            'alamat' => $attributes['alamat'] ?? ($serviceOrder->customer_address ?? $serviceOrder->service_location_address ?? 'Online'),
            'catatan' => $attributes['catatan'] ?? $serviceOrder->booking_note ?? null,
            'catatan_alamat' => $attributes['catatan_alamat'] ?? null,
            'tanggal' => $bookingDate ?? now()->toDateString(),
            'waktu' => $bookingTime ?? now()->format('H:i'),
            'metode_pembayaran' => $attributes['metode_pembayaran'] ?? $serviceOrder->payment_method ?? 'COD',
            'payment_method' => $attributes['payment_method'] ?? $serviceOrder->payment_method ?? 'COD',
            'payment_status' => $attributes['payment_status'] ?? 'PENDING',
            'promo_code' => $attributes['promo_code'] ?? null,
            'total' => $serviceOrder->total_price,
            'total_price' => $serviceOrder->total_price,
            'status' => $attributes['status'] ?? 'pending',
            'mekanisme_pemesanan' => $serviceOrder->mekanisme_pemesanan ?? $attributes['mekanisme_pemesanan'] ?? null,
        ]);

        JasaOrderItem::create([
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
            'service_type_booking' => $attributes['service_type_booking'] ?? null,
            'note' => $attributes['note'] ?? $serviceOrder->booking_note ?? null,
        ]);

        return $order;
    }
}