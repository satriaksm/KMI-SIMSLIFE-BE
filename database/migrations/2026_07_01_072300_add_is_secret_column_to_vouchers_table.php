<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vouchers', 'is_secret')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->boolean('is_secret')->default(false)->after('voucher_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vouchers', 'is_secret')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropColumn('is_secret');
            });
        }
    }
};