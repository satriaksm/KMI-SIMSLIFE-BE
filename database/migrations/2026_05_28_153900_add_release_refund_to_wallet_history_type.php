<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tambah 'release' dan 'refund' ke ENUM type
        DB::statement("ALTER TABLE `merchant_wallet_histories` MODIFY `type` ENUM('credit', 'debit', 'release', 'refund') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `merchant_wallet_histories` MODIFY `type` ENUM('credit', 'debit') NOT NULL");
    }
};
