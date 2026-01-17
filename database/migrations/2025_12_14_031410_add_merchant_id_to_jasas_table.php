<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Add column only if missing
        Schema::table('jasas', function (Blueprint $table) {
            if (!Schema::hasColumn('jasas', 'merchant_id')) {
                $table->foreignId('merchant_id')->nullable()->after('id');
            }
        });

        // 2) Add FK only if it doesn't exist yet
        // (try/catch biar aman kalau FK sudah pernah kebentuk manual)
        try {
            Schema::table('jasas', function (Blueprint $table) {
                $table->foreign('merchant_id')
                    ->references('id')
                    ->on('merchants')
                    ->cascadeOnDelete();
            });
        } catch (\Throwable $e) {
            // ignore: FK mungkin sudah ada / nama constraint beda
        }
    }

    public function down(): void
    {
        // drop FK if exists
        try {
            Schema::table('jasas', function (Blueprint $table) {
                $table->dropForeign(['merchant_id']);
            });
        } catch (\Throwable $e) {}

        // drop column if exists
        Schema::table('jasas', function (Blueprint $table) {
            if (Schema::hasColumn('jasas', 'merchant_id')) {
                $table->dropColumn('merchant_id');
            }
        });
    }
};
