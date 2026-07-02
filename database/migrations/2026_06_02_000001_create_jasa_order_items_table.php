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
            $table->foreignId('jasa_id')->constrained('jasas')->onDelete('cascade');
            $table->string('service_type_snapshot');
            $table->string('jasa_title_snapshot')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price_snapshot', 12, 2);
            $table->decimal('subtotal_snapshot', 12, 2);
            $table->text('image_snapshot_path');
            $table->date('booking_date');
            $table->time('booking_time');

            // Lokasi Customer (Saat order)
            $table->string('customer_province_snapshot')->nullable();
            $table->string('customer_city_snapshot')->nullable();
            $table->string('customer_district_snapshot')->nullable();
            $table->string('customer_village_snapshot')->nullable();
            $table->text('customer_address_snapshot')->nullable();
            $table->decimal('customer_latitude_snapshot', 10, 7)->nullable();
            $table->decimal('customer_longitude_snapshot', 10, 7)->nullable();            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasa_order_items');
    }
};
