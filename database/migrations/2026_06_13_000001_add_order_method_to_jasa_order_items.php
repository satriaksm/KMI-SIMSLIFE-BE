<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rename/refactor order_type mechanism for jasa orders:
     * - OLD: service_type_booking (keranjang/booking/konsultasi)
     * - NEW: order_method (direct/scheduled/consultation)
     *
     * Mapping:
     * - keranjang, direct_checkout, langsung_pesan -> direct
     * - booking -> scheduled
     * - konsultasi, consultation -> consultation
     */
    public function up(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            // Add new order_method column if not exists
            if (!Schema::hasColumn('jasa_order_items', 'order_method')) {
                $table->string('order_method', 50)->nullable()->after('service_type_booking');
            }
        });

        // Migrate existing data from service_type_booking to order_method
        // Only migrate non-null values
        \Illuminate\Support\Facades\DB::table('jasa_order_items')
            ->whereNotNull('service_type_booking')
            ->whereNull('order_method')
            ->update([
                'order_method' => \Illuminate\Support\Facades\DB::raw("CASE
                    WHEN service_type_booking IN ('keranjang', 'langsung_pesan', 'direct_checkout', 'langsung_dibayar')
                    THEN 'direct'
                    WHEN service_type_booking IN ('booking')
                    THEN 'scheduled'
                    WHEN service_type_booking IN ('konsultasi', 'consultation')
                    THEN 'consultation'
                    ELSE service_type_booking
                END")
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('jasa_order_items', 'order_method')) {
                $table->dropColumn('order_method');
            }
        });
    }
};
