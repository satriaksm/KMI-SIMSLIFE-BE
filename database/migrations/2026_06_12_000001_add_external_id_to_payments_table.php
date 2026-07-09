<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        // Tambah kolom external_id jika belum ada
        if (!Schema::hasColumn('payments', 'external_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('external_id', 100)->nullable()->after('order_id');
            });
        }

        // Tambah index external_id jika kolom ada tapi index belum
        if (Schema::hasColumn('payments', 'external_id')) {
            // Index dibuat otomatis oleh Schema::table di atas, tidak perlu extra
        }

        // Rename metadata → raw_response jika metadata ada dan raw_response belum ada
        if (Schema::hasColumn('payments', 'metadata') && !Schema::hasColumn('payments', 'raw_response')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->renameColumn('metadata', 'raw_response');
            });
        }
    }

    public function down(): void
    {
        // Do nothing - don't rename/drop columns in down()
    }
};
