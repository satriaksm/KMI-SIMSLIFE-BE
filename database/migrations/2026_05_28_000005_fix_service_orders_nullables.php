<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dateTime('booking_date')->nullable()->change();
            $table->string('booking_time', 20)->nullable()->change();
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dateTime('booking_date')->nullable(false)->change();
            $table->string('booking_time', 20)->nullable(false)->change();
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};