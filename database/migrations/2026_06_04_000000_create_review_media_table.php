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
    if (!Schema::hasTable('review_media')) {
        Schema::create('review_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('ratings')->cascadeOnDelete();
            $table->string('file_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('file_type')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->tinyInteger('display_order')->default(0);
            $table->timestamps();
        });
    } else {
        Schema::table('review_media', function (Blueprint $table) {
            if (!Schema::hasColumn('review_media', 'file_path')) {
                $table->string('file_path')->nullable()->after('review_id');
            }

            if (!Schema::hasColumn('review_media', 'file_url')) {
                $table->string('file_url')->nullable()->after('file_path');
            }

            if (!Schema::hasColumn('review_media', 'file_type')) {
                $table->string('file_type')->nullable()->after('file_url');
            }

            if (!Schema::hasColumn('review_media', 'original_name')) {
                $table->string('original_name')->nullable()->after('file_type');
            }

            if (!Schema::hasColumn('review_media', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('file_type');
            }

            if (!Schema::hasColumn('review_media', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('original_name');
            }

            if (!Schema::hasColumn('review_media', 'display_order')) {
                $table->tinyInteger('display_order')->default(0)->after('file_size');
            }
        });
    }
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_media');
    }
};
