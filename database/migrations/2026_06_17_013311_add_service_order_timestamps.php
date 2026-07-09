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
            // Timestamps for service order lifecycle
            $table->timestamp('accepted_at')->nullable()->after('booking_time');
            $table->timestamp('started_at')->nullable()->after('accepted_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->timestamp('delivered_at')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['accepted_at', 'started_at', 'completed_at', 'delivered_at']);
        });
    }
};
