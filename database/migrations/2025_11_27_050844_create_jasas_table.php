<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jasas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique()->nullable();
            $table->string('vendor')->nullable();
            $table->integer('price')->default(0);
            $table->string('image')->nullable();
            $table->float('rating', 3, 1)->default(0);
            $table->float('distance_km', 5, 2)->nullable();
            $table->float('duration_hours', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('fixed_price')->default(0);
            $table->integer('base_price')->default(0);
            $table->string('service_type')->default('at_location'); // at_location | on_site | online
            $table->string('service_type_booking', 30)->default('keranjang');
            $table->string('location_address')->nullable();
            $table->string('service_area')->nullable();
            $table->text('special_notes')->nullable();
            $table->string('payment_methods')->nullable(); // contoh: "cod,transfer"
            $table->string('status')->default('draft'); // draft | active | inactive
            $table->string('operating_days')->nullable();   // contoh: "1,2,3,4,5,6,7"
            $table->string('operating_times')->nullable(); // contoh: "08.00,08.30,09.00"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasas');
    }
};
