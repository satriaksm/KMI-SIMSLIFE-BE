<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jasas', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->string('title');
            $table->string('slug', 150)->unique()->nullable();
            $table->text('description')->nullable();
            $table->integer('fixed_price')->default(0);
            $table->integer('base_price')->default(0);
            $table->enum('delivery_type', ['online', 'on-site', 'in-store'])->default('in-store');
            $table->enum('service_type_booking', ['keranjang', 'booking', 'konsultasi'])->default('keranjang');
            $table->string('location_address')->nullable();
            $table->text('special_notes')->nullable();
            $table->string('payment_methods', 50)->nullable(); // contoh: "cod,transfer"
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->string('operating_days', 30)->nullable();   // contoh: "1,2,3,4,5,6,7"
            $table->string('operating_times', 50)->nullable(); // contoh: "08.00,08.30,09.00"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasas');
    }
};
