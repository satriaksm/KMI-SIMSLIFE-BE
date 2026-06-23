<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('product_order_items', 'product_name_snapshot')) {
                $table->string('product_name_snapshot')->nullable()->after('product_variant_id');
            }
            if (!Schema::hasColumn('product_order_items', 'product_variant_snapshot')) {
                $table->string('product_variant_snapshot')->nullable()->after('product_name_snapshot');
            }
            if (!Schema::hasColumn('product_order_items', 'sku_snapshot')) {
                $table->text('sku_snapshot')->nullable()->after('product_variant_snapshot');
            }
            if (!Schema::hasColumn('product_order_items', 'image_snapshot_path')) {
                $table->text('image_snapshot_path')->nullable()->after('sku_snapshot');
            }
            if (!Schema::hasColumn('product_order_items', 'unit_price_snapshot')) {
                $table->decimal('unit_price_snapshot', 12, 2)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('product_order_items', 'subtotal_snapshot')) {
                $table->decimal('subtotal_snapshot', 12, 2)->nullable()->after('unit_price_snapshot');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_order_items', function (Blueprint $table) {
            $cols = [
                'product_name_snapshot', 'product_variant_snapshot',
                'sku_snapshot', 'image_snapshot_path',
                'unit_price_snapshot', 'subtotal_snapshot'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('product_order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
