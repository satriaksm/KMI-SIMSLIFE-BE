<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('orders')
            ->whereNull('order_type')
            ->update(['order_type' => 'product']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: data update migration, tidak perlu rollback otomatis
    }
};
