<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add location and service address fields for service orders.
     * These are needed for service_type based address handling:
     * - ke_rumah_pelanggan: needs customer_address + customer coordinates
     * - di_tempat_umkm: needs service_location_address (merchant address)
     * - online: no physical address needed
     */
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            // Customer location coordinates (for ke_rumah_pelanggan)
            if (!Schema::hasColumn('service_orders', 'customer_latitude')) {
                $table->decimal('customer_latitude', 10, 7)->nullable()->after('customer_address');
            }
            if (!Schema::hasColumn('service_orders', 'customer_longitude')) {
                $table->decimal('customer_longitude', 10, 7)->nullable()->after('customer_latitude');
            }
            // Service location address (for di_tempat_umkm - merchant's address)
            if (!Schema::hasColumn('service_orders', 'service_location_address')) {
                $table->string('service_location_address', 500)->nullable()->after('customer_longitude');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['customer_latitude', 'customer_longitude', 'service_location_address']);
        });
    }
};