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
        Schema::table('review_media', function (Blueprint $table) {
            if (!Schema::hasColumn('review_media', 'file_path')) {
                $table->string('file_path')->after('review_id');
            }

            if (!Schema::hasColumn('review_media', 'file_type')) {
                $table->string('file_type')->after('file_path');
            }

            if (!Schema::hasColumn('review_media', 'original_name')) {
                $table->string('original_name')->nullable()->after('file_type');
            }

            if (!Schema::hasColumn('review_media', 'file_size')) {
                $table->integer('file_size')->nullable()->after('original_name');
            }

            if (!Schema::hasColumn('review_media', 'display_order')) {
                $table->integer('display_order')->default(0)->after('file_size');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('review_media', function (Blueprint $table) {
            $columns = ['file_path', 'file_type', 'original_name', 'file_size', 'display_order'];
            $existingColumns = array_filter($columns, fn($col) => Schema::hasColumn('review_media', $col));
            if (!empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};