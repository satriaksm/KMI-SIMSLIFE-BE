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
            $table->unsignedInteger('addon_group_id');
            $table->unsignedInteger('addon_id');

            // 🔒 SNAPSHOT
            $table->string('addon_name_snapshot')->nullable();
            $table->decimal('addon_price_snapshot', 12, 2);

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
