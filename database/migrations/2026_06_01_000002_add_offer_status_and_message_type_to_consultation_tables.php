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
            if (!Schema::hasColumn('service_consultations', 'offer_status')) {
                $table->string('offer_status', 30)->nullable()->after('merchant_note');
            }
        });

        Schema::table('consultation_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('consultation_messages', 'message_type')) {
                $table->string('message_type', 50)->nullable()->after('message');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table) {
            if (Schema::hasColumn('consultation_messages', 'message_type')) {
                $table->dropColumn('message_type');
            }
        });

        Schema::table('service_consultations', function (Blueprint $table) {
            if (Schema::hasColumn('service_consultations', 'offer_status')) {
                $table->dropColumn('offer_status');
            }
        });
    }
};
