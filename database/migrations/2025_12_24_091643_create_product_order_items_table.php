<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->unsignedInteger('product_variant_id')->nullable();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->string('product_variant_snapshot')->nullable();
            $table->text('sku_snapshot')->nullable();
            $table->text('image_snapshot_path');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('subtotal_snapshot', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_order_items');
    }
};