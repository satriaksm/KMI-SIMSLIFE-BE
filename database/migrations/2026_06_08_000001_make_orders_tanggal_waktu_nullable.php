<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Make tanggal nullable for orders without schedule (keranjang/checkout tanpa jadwal)
            if (Schema::hasColumn('orders', 'tanggal')) {
                $table->date('tanggal')->nullable()->change();
            }

            // Make waktu nullable for orders without schedule
            if (Schema::hasColumn('orders', 'waktu')) {
                $table->string('waktu', 10)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Revert to NOT NULL (with default date for existing rows)
            if (Schema::hasColumn('orders', 'tanggal')) {
                $table->date('tanggal')->nullable(false)->change();
            }

            if (Schema::hasColumn('orders', 'waktu')) {
                $table->string('waktu', 10)->nullable(false)->change();
            }
        });
    }
};
