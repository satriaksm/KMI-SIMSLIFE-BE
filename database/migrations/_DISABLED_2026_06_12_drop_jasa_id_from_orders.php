<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Remove foreign key constraint first, then drop column
            // The relationship to jasas is through jasa_order_items, not direct foreign key on orders
            if (Schema::hasColumn('orders', 'jasa_id')) {
                $table->dropForeign(['jasa_id']);
                $table->dropColumn('jasa_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Rollback: re-add the column (for migrations:refresh only)
            // NOTE: This rollback is NOT recommended for production - jasa_id should be removed permanently
            if (!Schema::hasColumn('orders', 'jasa_id')) {
                $table->foreignId('jasa_id')->nullable()->constrained('jasas')->cascadeOnDelete();
            }
        });
    }
};
