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
            if (!Schema::hasColumn('orders', 'order_code')) {
                $table->string('order_code', 50)->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('orders', 'delivery_type')) {
                $table->string('delivery_type', 50)->default('pickup')->after('metode_pembayaran');
            }
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 50)->default('COD')->after('metode_pembayaran');
            }
            if (!Schema::hasColumn('orders', 'subtotal')) {
                $table->decimal('subtotal', 15, 2)->default(0)->after('promo_code');
            }
            if (!Schema::hasColumn('orders', 'discount_total')) {
                $table->decimal('discount_total', 15, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('orders', 'shipping_fee')) {
                $table->decimal('shipping_fee', 15, 2)->default(0)->after('discount_total');
            }
            $table->string('status', 50)->default('pending')->change();
            $table->string('metode_pembayaran', 50)->default('COD')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'order_code')) {
                $table->dropColumn('order_code');
            }
            if (Schema::hasColumn('orders', 'delivery_type')) {
                $table->dropColumn('delivery_type');
            }
            if (Schema::hasColumn('orders', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('orders', 'subtotal')) {
                $table->dropColumn('subtotal');
            }
            if (Schema::hasColumn('orders', 'discount_total')) {
                $table->dropColumn('discount_total');
            }
            if (Schema::hasColumn('orders', 'shipping_fee')) {
                $table->dropColumn('shipping_fee');
            }
        });
    }
};
