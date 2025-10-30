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
        // gunakan nama tabel yang benar: addresses
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            // relasi polimorfik: addressable_id & addressable_type + index
            $table->morphs('addressable');

            // ID wilayah (tanpa FK, sesuaikan jika punya master table)
            $table->foreignId('province_id')->constrained('provinces')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('district_id')->constrained('districts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('village_id')->constrained('villages')->cascadeOnUpdate()->restrictOnDelete();

            // koordinat
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // detail alamat
            $table->text('detail')->nullable();
            $table->string('label', 50)->nullable();

            $table->timestamps();

            $table->index(['province_id', 'city_id', 'district_id', 'village_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};