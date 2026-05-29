<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Temporary flow without payment gateway.
     * Order is considered created after WhatsApp redirect.
     * Future implementation will use payment gateway callback.
     */
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->foreignId('jasa_id')->constrained('jasas')->onDelete('cascade');
            $table->string('service_name');
            $table->string('service_image')->nullable();
            $table->string('merchant_name');
            $table->decimal('total_price', 12, 2)->default(0);
            $table->enum('status', ['layanan_diproses', 'layanan_dikerjakan', 'selesai'])->default('layanan_diproses');
            $table->date('booking_date')->nullable();
            $table->time('booking_time')->nullable();
            $table->text('booking_note')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('whatsapp_redirect_url')->nullable();
            $table->string('payment_method')->nullable(); // COD, QRIS
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('review_id')->nullable()->constrained('ratings')->onDelete('set null');
            $table->timestamps();

            // Indexes for faster queries
            $table->index('customer_id');
            $table->index('merchant_id');
            $table->index('status');
            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};