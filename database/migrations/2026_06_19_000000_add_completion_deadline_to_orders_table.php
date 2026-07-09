<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // SLA: deadline customer harus konfirmasi selesai
            // Dibuat saat merchant upload bukti pengerjaan (menunggu_konfirmasi_selesai)
            $table->timestamp('completion_deadline_at')->nullable()->after('completion_submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('completion_deadline_at');
        });
    }
};
