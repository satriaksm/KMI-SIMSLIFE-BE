<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update ratings table to support order-based reviews for all UMKM segments.
     *
     * Changes:
     * 1. Add order references (order_id, order_item_id) for Toko/Kuliner
     * 2. Add service_order_id for Jasa
     * 3. Add is_anonymous flag
     * 4. Add new unique constraint per service order (keeps old constraint for products)
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // Add order references for Toko/Kuliner
            if (!Schema::hasColumn('ratings', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->after('merchant_id');
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('ratings', 'order_item_id')) {
                $table->unsignedBigInteger('order_item_id')->nullable()->after('order_id');
                $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            }

            // Add service_order_id for Jasa
            if (!Schema::hasColumn('ratings', 'service_order_id')) {
                $table->unsignedBigInteger('service_order_id')->nullable()->after('order_item_id');
                $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
            }

            // Add is_anonymous flag
            if (!Schema::hasColumn('ratings', 'is_anonymous')) {
                $table->boolean('is_anonymous')->default(false)->after('comment');
            }
        });

        // Add new unique constraint for service orders
        // This allows one review per user per service order
        Schema::table('ratings', function (Blueprint $table) {
            if (!Schema::hasIndex('ratings', 'ratings_user_service_order_unique')) {
                $table->unique(['user_id', 'service_order_id'], 'ratings_user_service_order_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // Drop new constraint
            if (Schema::hasIndex('ratings', 'ratings_user_service_order_unique')) {
                $table->dropUnique('ratings_user_service_order_unique');
            }

            // Drop columns
            if (Schema::hasColumn('ratings', 'is_anonymous')) {
                $table->dropColumn('is_anonymous');
            }
            if (Schema::hasColumn('ratings', 'service_order_id')) {
                $table->dropForeign(['service_order_id']);
                $table->dropColumn('service_order_id');
            }
            if (Schema::hasColumn('ratings', 'order_item_id')) {
                $table->dropForeign(['order_item_id']);
                $table->dropColumn('order_item_id');
            }
            if (Schema::hasColumn('ratings', 'order_id')) {
                $table->dropForeign(['order_id']);
                $table->dropColumn('order_id');
            }
        });
    }
};