<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jasa_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->unsignedInteger('jasa_id');
            $table->foreign('jasa_id')->references('id')->on('jasas')->onDelete('cascade');
            $table->foreignId('service_consultation_id')->nullable()->constrained('service_consultations')->nullOnDelete();
            
            // Core Order Item Fields
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->string('order_method')->default('keranjang'); // keranjang, booking, konsultasi
            
            // Booking Details
            $table->date('booking_date')->nullable();
            $table->time('booking_time')->nullable();

            
            // Snapshots - Service Info
            $table->string('jasa_title_snapshot')->nullable();
            $table->text('jasa_image_snapshot')->nullable();
            $table->decimal('jasa_price_snapshot', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasa_order_items');
    }
};
