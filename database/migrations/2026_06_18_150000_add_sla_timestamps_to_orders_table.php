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
        Schema::table('orders', function (Blueprint $table) {
            // SLA: merchant must respond within 24 hours
            $table->timestamp('merchant_response_deadline')->nullable()->after('confirm_deadline');
            $table->timestamp('merchant_responded_at')->nullable()->after('merchant_response_deadline');

            // SLA: customer must confirm completion within 24 hours after evidence upload
            $table->timestamp('completion_submitted_at')->nullable()->after('merchant_responded_at');

            // Auto-complete tracking
            $table->timestamp('auto_completed_at')->nullable()->after('completion_submitted_at');
            $table->string('completed_by')->nullable()->after('auto_completed_at'); // 'customer' | 'system'

            // Expired tracking
            $table->timestamp('expired_at')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'merchant_response_deadline',
                'merchant_responded_at',
                'completion_submitted_at',
                'auto_completed_at',
                'completed_by',
                'expired_at',
            ]);
        });
    }
};
