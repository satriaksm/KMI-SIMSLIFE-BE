<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make legacy product order item fields nullable.
     */
    public function up(): void
    {
        Schema::table('product_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('product_order_items', 'price')) {
                $table->decimal('price', 12, 2)->nullable()->change();
            }

            if (Schema::hasColumn('product_order_items', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('product_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('product_order_items', 'price')) {
                $table->decimal('price', 12, 2)->nullable(false)->change();
            }

            if (Schema::hasColumn('product_order_items', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->nullable(false)->change();
            }
        });
    }
};
