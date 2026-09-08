<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('voucher_merchant_products');

        Schema::create('voucher_merchant_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->timestamps();

            $table->unique(['voucher_id', 'merchant_id', 'item_type', 'item_id'], 'vmi_unique');
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_merchant_items');

        Schema::create('voucher_merchant_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['voucher_id', 'merchant_id', 'product_id'], 'vmp_unique');
        });
    }
};
