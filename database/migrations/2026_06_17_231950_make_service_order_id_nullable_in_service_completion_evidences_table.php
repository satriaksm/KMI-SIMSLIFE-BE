<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Make service_order_id nullable, add jasa_order_item_id if not exists.
     */
    public function up(): void
    {
        // Use raw SQL for compatibility with all MySQL versions
        // Make service_order_id nullable
        DB::statement("ALTER TABLE service_completion_evidences MODIFY service_order_id BIGINT UNSIGNED NULL");

        // Add jasa_order_item_id column if not exists
        if (!Schema::hasColumn('service_completion_evidences', 'jasa_order_item_id')) {
            Schema::table('service_completion_evidences', function (Blueprint $table) {
                $table->unsignedBigInteger('jasa_order_item_id')->nullable()->after('id');
                $table->foreign('jasa_order_item_id')
                    ->references('id')
                    ->on('jasa_order_items')
                    ->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove foreign key first
        if (Schema::hasColumn('service_completion_evidences', 'jasa_order_item_id')) {
            Schema::table('service_completion_evidences', function (Blueprint $table) {
                $table->dropForeign(['jasa_order_item_id']);
                $table->dropColumn('jasa_order_item_id');
            });
        }

        // Make service_order_id NOT nullable again
        DB::statement("ALTER TABLE service_completion_evidences MODIFY service_order_id BIGINT UNSIGNED NOT NULL");
    }
};
