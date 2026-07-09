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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ready_to_pickup_at')->nullable()->after('delivered_at');
            $table->timestamp('unpicked_at')->nullable()->after('ready_to_pickup_at');
            $table->timestamp('undelivered_at')->nullable()->after('unpicked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ready_to_pickup_at', 'unpicked_at', 'undelivered_at']);
        });
    }
};
