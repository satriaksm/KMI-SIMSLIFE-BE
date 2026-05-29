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
        Schema::table('rating_summaries', function (Blueprint $table) {
            if (!Schema::hasColumn('rating_summaries', 'summaryable_id')) {
                $table->unsignedBigInteger('summaryable_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('rating_summaries', 'summaryable_type')) {
                $table->string('summaryable_type')->nullable()->after('summaryable_id');
            }

            // Add unique constraint for polymorphic summaries (1 per item)
            if (Schema::hasColumn('rating_summaries', 'summaryable_id') &&
                Schema::hasColumn('rating_summaries', 'summaryable_type')) {
                $table->unique(['summaryable_id', 'summaryable_type'], 'rating_summaries_summaryable_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rating_summaries', function (Blueprint $table) {
            if (Schema::hasColumn('rating_summaries', 'summaryable_id')) {
                $table->dropUnique('rating_summaries_summaryable_unique');
            }

            $columns = array_filter([
                Schema::hasColumn('rating_summaries', 'summaryable_id') ? 'summaryable_id' : null,
                Schema::hasColumn('rating_summaries', 'summaryable_type') ? 'summaryable_type' : null,
            ]);

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};