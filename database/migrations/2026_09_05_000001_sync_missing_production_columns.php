<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1. Tambah is_super_admin ke users jika belum ada
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'is_super_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_super_admin')->default(false)->after('status');
            });
        }

        // 2. Tambah reason_description ke report_reasons jika belum ada
        if (Schema::hasTable('report_reasons') && !Schema::hasColumn('report_reasons', 'reason_description')) {
            Schema::table('report_reasons', function (Blueprint $table) {
                $table->text('reason_description')->nullable()->after('reason_title');
            });
        }

        // 3. Update ENUM applies_to pada report_reasons menyertakan 'user'
        if (Schema::hasTable('report_reasons')) {
            DB::statement("ALTER TABLE `report_reasons` MODIFY COLUMN `applies_to` ENUM('product', 'service', 'merchant', 'post', 'post_comment', 'user') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_super_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_super_admin');
            });
        }

        if (Schema::hasTable('report_reasons') && Schema::hasColumn('report_reasons', 'reason_description')) {
            Schema::table('report_reasons', function (Blueprint $table) {
                $table->dropColumn('reason_description');
            });
        }
    }
};
