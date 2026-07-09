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
        Schema::table('ratings', function (Blueprint $table) {
            // Check if constraint exists using information_schema
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'ratings'
                  AND CONSTRAINT_NAME = 'ratings_order_item_id_foreign'
            ");

            if (!empty($foreignKeys)) {
                $table->dropForeign('ratings_order_item_id_foreign');
            }

            // Create new foreign key referencing product_order_items
            $table->foreign('order_item_id')
                ->references('id')
                ->on('product_order_items')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'ratings'
                  AND CONSTRAINT_NAME = 'ratings_order_item_id_foreign'
            ");

            if (!empty($foreignKeys)) {
                $table->dropForeign('ratings_order_item_id_foreign');
            }

            // Revert back to referencing order_items
            $table->foreign('order_item_id')
                ->references('id')
                ->on('order_items')
                ->onDelete('cascade');
        });
    }
};
