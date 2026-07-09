<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add jasa_order_item_id to service_completion_evidences table.
     * This supports the new Order + JasaOrderItem flow.
     *
     * - Nullable: data lama (legacy) yang hanya punya service_order_id tidak terganggu.
     * - Tidak ada default: harus diisi saat evidence baru dibuat.
     * - Foreign key deferred agar tidak blok migrasi jika data legacy belum punya mapping.
     */
    public function up(): void
    {
        Schema::table('service_completion_evidences', function (Blueprint $table) {
            $table
                ->foreignId('jasa_order_item_id')
                ->nullable()
                ->after('service_order_id')
                ->constrained('jasa_order_items')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_completion_evidences', function (Blueprint $table) {
            $table->dropForeign(['jasa_order_item_id']);
            $table->dropColumn('jasa_order_item_id');
        });
    }
};
