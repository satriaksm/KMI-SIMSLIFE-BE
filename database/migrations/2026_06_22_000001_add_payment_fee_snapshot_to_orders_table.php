<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_fee_snapshot')) {
                $table->decimal('payment_fee_snapshot', 14, 2)->nullable()->after('platform_fee_snapshot');
            }
            if (!Schema::hasColumn('orders', 'payment_channel_snapshot')) {
                $table->string('payment_channel_snapshot', 50)->nullable()->after('payment_method_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_fee_snapshot')) {
                $table->dropColumn('payment_fee_snapshot');
            }
            if (Schema::hasColumn('orders', 'payment_channel_snapshot')) {
                $table->dropColumn('payment_channel_snapshot');
            }
        });
    }
};
