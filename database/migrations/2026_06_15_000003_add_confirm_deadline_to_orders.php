<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Confirm deadline: batas waktu merchant merespon pesanan setelah pembayaran sukses
            // Untuk COD: langsung diisi saat order dibuat
            // Untuk Transfer: diisi setelah webhook mengubah payment menjadi PAID
            if (!Schema::hasColumn('orders', 'confirm_deadline')) {
                $table->timestamp('confirm_deadline')->nullable()->after('status');
                $table->index('confirm_deadline');
            }

            // Cancelled at timestamp for tracking cancelled orders
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'confirm_deadline')) {
                $table->dropIndex(['confirm_deadline']);
                $table->dropColumn('confirm_deadline');
            }
            if (Schema::hasColumn('orders', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
