<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add jasa_order_item_id column to ratings table to support
     * the unified order system where jasa order items can have reviews.
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (!Schema::hasColumn('ratings', 'jasa_order_item_id')) {
                $table->unsignedBigInteger('jasa_order_item_id')->nullable()->after('order_item_id');

                // Add foreign key constraint
                $table->foreign('jasa_order_item_id')
                    ->references('id')
                    ->on('jasa_order_items')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (Schema::hasColumn('ratings', 'jasa_order_item_id')) {
                $table->dropForeign(['jasa_order_item_id']);
                $table->dropColumn('jasa_order_item_id');
            }
        });
    }
};