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
        Schema::table('service_consultations', function (Blueprint $table) {
            $table->date('proposed_date')->nullable()->after('agreed_deadline');
            $table->time('proposed_time')->nullable()->after('proposed_date');
            $table->string('proposed_notes', 500)->nullable()->after('proposed_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_consultations', function (Blueprint $table) {
            //
        });
    }
};
