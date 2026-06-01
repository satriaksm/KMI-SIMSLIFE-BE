<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('service_orders', 'mekanisme_pemesanan')) {
                $table->string('mekanisme_pemesanan', 50)->nullable()->after('booking_note');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (Schema::hasColumn('service_orders', 'mekanisme_pemesanan')) {
                $table->dropColumn('mekanisme_pemesanan');
            }
        });
    }
};