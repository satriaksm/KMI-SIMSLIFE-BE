<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Snapshot fields to preserve transaction data at the time of purchase.
     * These fields capture the state of related entities (customer, merchant) at order time.
     * If customer/merchant data changes after order, historical data remains intact.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Customer snapshot
            $table->string('customer_name_snapshot')->nullable()->after('address_detail_snapshot');
            $table->string('customer_phone_snapshot')->nullable()->after('customer_name_snapshot');
            $table->text('customer_address_snapshot')->nullable()->after('customer_phone_snapshot');

            // Merchant snapshot
            $table->string('merchant_name_snapshot')->nullable()->after('customer_address_snapshot');
            $table->string('merchant_phone_snapshot')->nullable()->after('merchant_name_snapshot');
            $table->text('merchant_address_snapshot')->nullable()->after('merchant_phone_snapshot');

            // Payment snapshot
            $table->string('payment_method_snapshot')->nullable()->after('merchant_address_snapshot');
            $table->string('payment_channel_snapshot')->nullable()->after('payment_method_snapshot');

            // Price breakdown snapshot
            $table->decimal('subtotal_snapshot', 14, 2)->nullable()->after('payment_channel_snapshot');
            $table->decimal('admin_fee_snapshot', 14, 2)->nullable()->after('subtotal_snapshot');
            $table->decimal('platform_fee_snapshot', 14, 2)->nullable()->after('admin_fee_snapshot');
            $table->decimal('total_payment_snapshot', 14, 2)->nullable()->after('platform_fee_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Remove snapshot fields in reverse order
            $table->dropColumn([
                'customer_name_snapshot',
                'customer_phone_snapshot',
                'customer_address_snapshot',
                'merchant_name_snapshot',
                'merchant_phone_snapshot',
                'merchant_address_snapshot',
                'payment_method_snapshot',
                'payment_channel_snapshot',
                'subtotal_snapshot',
                'admin_fee_snapshot',
                'platform_fee_snapshot',
                'total_payment_snapshot',
            ]);
        });
    }
};
