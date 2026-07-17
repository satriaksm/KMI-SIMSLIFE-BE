<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voucher_merchant_products', function (Blueprint $table) {
            $table->id();
            // Link to voucher_merchants (the pivot)
            $table->unsignedInteger('voucher_id');
            $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['voucher_id', 'merchant_id', 'product_id'], 'vmp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_merchant_products');
    }
};
