<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rating_summaries', function (Blueprint $table) {
            if (!Schema::hasColumn('rating_summaries', 'summaryable_id')) {
                $table->unsignedBigInteger('summaryable_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('rating_summaries', 'summaryable_type')) {
                $table->string('summaryable_type')->nullable()->after('summaryable_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rating_summaries', function (Blueprint $table) {
            if (Schema::hasColumn('rating_summaries', 'summaryable_type')) {
                $table->dropColumn('summaryable_type');
            }

            if (Schema::hasColumn('rating_summaries', 'summaryable_id')) {
                $table->dropColumn('summaryable_id');
            }
        });
    }
};