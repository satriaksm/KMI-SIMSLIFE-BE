<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add mime_type column to review_media table.
     */
    public function up(): void
    {
        Schema::table('review_media', function (Blueprint $table) {
            if (!Schema::hasColumn('review_media', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('file_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('review_media', function (Blueprint $table) {
            if (Schema::hasColumn('review_media', 'mime_type')) {
                $table->dropColumn('mime_type');
            }
        });
    }
};