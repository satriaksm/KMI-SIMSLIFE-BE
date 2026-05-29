<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            // Action tracking
            if (!Schema::hasColumn('content_reports', 'action_taken')) {
                $table->string('action_taken')->nullable()->after('reviewed_at');
            }

            // Forwarding fields
            if (!Schema::hasColumn('content_reports', 'forwarded_to')) {
                $table->foreignId('forwarded_to')->nullable()->constrained('users')->onDelete('set null')->after('action_taken');
            }
            if (!Schema::hasColumn('content_reports', 'forwarded_by')) {
                $table->foreignId('forwarded_by')->nullable()->constrained('users')->onDelete('set null')->after('forwarded_to');
            }
            if (!Schema::hasColumn('content_reports', 'forwarded_at')) {
                $table->timestamp('forwarded_at')->nullable()->after('forwarded_by');
            }
            if (!Schema::hasColumn('content_reports', 'forward_message')) {
                $table->text('forward_message')->nullable()->after('forwarded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            $table->dropForeign(['forwarded_to']);
            $table->dropForeign(['forwarded_by']);
            $table->dropColumn(['action_taken', 'forwarded_to', 'forwarded_by', 'forwarded_at', 'forward_message']);
        });
    }
};
