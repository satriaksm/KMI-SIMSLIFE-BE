<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SLA Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi durasi SLA dalam jam untuk alur pesanan jasa.
    |
    */

    // SLA Respon Merchant — batas waktu merchant menerima/menolak pesanan
    'merchant_response_hours' => env('SLA_MERCHANT_RESPONSE_HOURS', 24),

    // SLA Konfirmasi Selesai Customer — batas waktu customer mengkonfirmasi setelah bukti diupload
    'customer_confirm_hours' => env('SLA_CUSTOMER_CONFIRM_HOURS', 24),
];
