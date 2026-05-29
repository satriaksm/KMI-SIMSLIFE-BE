<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            // Simpan konten asli komentar/postingan sebelum dimodifikasi admin
            $table->text('original_content')->nullable()->after('action_taken');
        });
    }

    public function down(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            $table->dropColumn('original_content');
        });
    }
};
