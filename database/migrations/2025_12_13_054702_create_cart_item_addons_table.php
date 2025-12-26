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
        Schema::create('cart_item_addons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_item_id')
                ->constrained()
                ->cascadeOnDelete();

            // ❌ TIDAK FK — snapshot pointer
            $table->unsignedBigInteger('addon_group_id');
            $table->unsignedBigInteger('addon_id');

            // 🔒 SNAPSHOT
            $table->string('addon_name_snapshot')->nullable();
            $table->integer('addon_price_snapshot');

            $table->timestamps();

            // Optional indexes
            $table->index('addon_group_id');
            $table->index('addon_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_item_addons');
    }
};
