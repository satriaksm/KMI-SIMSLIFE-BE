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
        Schema::create('product_order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('addon_id')->nullable();
            $table->foreign('addon_id')->references('id')->on('addons')->nullOnDelete();
            $table->string('addon_name_snapshot');
            $table->decimal('addon_price_snapshot', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_order_item_addons');
    }
};
