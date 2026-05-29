<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add service_type field to service_orders to track the type of service
     * for each order (online, di_tempat_umkm, ke_rumah_pelanggan).
     */
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('service_orders', 'service_type')) {
                $table->string('service_type', 30)->nullable()->after('service_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (Schema::hasColumn('service_orders', 'service_type')) {
                $table->dropColumn('service_type');
            }
        });
    }
};
