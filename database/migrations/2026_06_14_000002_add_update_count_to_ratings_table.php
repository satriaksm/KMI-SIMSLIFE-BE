<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add update_count and review_updated_at to ratings table.
     * - update_count: counts how many times a review has been updated (max 1)
     * - review_updated_at: timestamp of last update
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (!Schema::hasColumn('ratings', 'update_count')) {
                $table->unsignedTinyInteger('update_count')->default(0)->after('is_anonymous');
            }
            if (!Schema::hasColumn('ratings', 'review_updated_at')) {
                $table->timestamp('review_updated_at')->nullable()->after('update_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (Schema::hasColumn('ratings', 'review_updated_at')) {
                $table->dropColumn('review_updated_at');
            }
            if (Schema::hasColumn('ratings', 'update_count')) {
                $table->dropColumn('update_count');
            }
        });
    }
};
