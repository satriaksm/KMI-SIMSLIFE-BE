<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update service_type_booking enum to support consultation.
     *
     * Old values: 'cart', 'booking'
     * New values: 'keranjang', 'booking', 'konsultasi'
     */
    public function up(): void
    {
        Schema::table('jasas', function (Blueprint $table) {
            // Change from enum to varchar for flexibility, then we'll use consistent values
            // Use varchar(30) to support the new values
            if (Schema::hasColumn('jasas', 'service_type_booking')) {
                $table->string('service_type_booking', 30)->default('keranjang')->change();
            }
        });

        // Update existing records to use new consistent values
        \Illuminate\Support\Facades\DB::table('jasas')
            ->where('service_type_booking', 'cart')
            ->update(['service_type_booking' => 'keranjang']);

        \Illuminate\Support\Facades\DB::table('jasas')
            ->where('service_type_booking', 'consultation')
            ->update(['service_type_booking' => 'konsultasi']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to old values
        \Illuminate\Support\Facades\DB::table('jasas')
            ->where('service_type_booking', 'keranjang')
            ->update(['service_type_booking' => 'cart']);

        \Illuminate\Support\Facades\Facades\DB::table('jasas')
            ->where('service_type_booking', 'konsultasi')
            ->update(['service_type_booking' => 'booking']);

        Schema::table('jasas', function (Blueprint $table) {
            if (Schema::hasColumn('jasas', 'service_type_booking')) {
                $table->enum('service_type_booking', ['cart', 'booking'])->default('cart')->change();
            }
        });
    }
};
