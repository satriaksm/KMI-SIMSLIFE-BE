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
        Schema::table('merchants', function (Blueprint $table) {
            if (!Schema::hasColumn('merchants', 'NPWP')) {
                $table->string('NPWP', 50)->nullable()->after('phone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (Schema::hasColumn('merchants', 'NPWP')) {
                $table->dropColumn('NPWP');
            }
        });
    }
};
