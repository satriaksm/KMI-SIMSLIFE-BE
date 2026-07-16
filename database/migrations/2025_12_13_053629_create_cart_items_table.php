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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            // Cart masih FK (aman)
            $table->foreignId('cart_id')
                ->constrained()
                ->cascadeOnDelete();

            // Polymorphic product reference (aman)
            $table->morphs('itemable');

            // ❌ TIDAK FK — hanya pointer
            $table->unsignedInteger('product_variant_id')->nullable();

            // 🔒 SNAPSHOT
            $table->string('itemable_name_snapshot');
            $table->string('product_variant_name_snapshot')->nullable();
            $table->string('image_snapshot_path')->nullable();

            $table->decimal('price_snapshot', 15, 2);
            $table->unsignedSmallInteger('quantity')->default(1);

            $table->timestamps();

            // Optional index (performance)
            $table->index('product_variant_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
