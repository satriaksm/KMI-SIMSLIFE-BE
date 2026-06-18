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
        Schema::table('service_completion_evidences', function (Blueprint $table) {
            $table->string('note', 1000)->nullable()->after('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_completion_evidences', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
