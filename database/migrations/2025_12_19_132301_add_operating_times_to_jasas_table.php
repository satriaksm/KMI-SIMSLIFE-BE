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
        Schema::table('jasas', function (Blueprint $table) {
            $table->string('operating_times')->nullable()->after('operating_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasas', function (Blueprint $table) {
            $table->dropColumn('operating_times');
        });
    }
};
