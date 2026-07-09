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
        // Add payment_channel to service_orders table
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('payment_channel', 100)->nullable()->after('payment_reference');
            $table->string('paid_channel', 100)->nullable()->after('payment_channel');
        });

        // Add payment_channel to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_channel', 100)->nullable()->after('payment_status');
            $table->string('paid_channel', 100)->nullable()->after('payment_channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'paid_channel']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'paid_channel']);
        });
    }
};