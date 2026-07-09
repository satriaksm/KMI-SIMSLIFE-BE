<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'xendit_refund_id')) {
                $table->string('xendit_refund_id')->nullable()->after('xendit_invoice_id');
            }
            if (!Schema::hasColumn('payments', 'refund_status')) {
                $table->string('refund_status')->nullable()->comment('processing, succeeded, failed, resolved')->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'xendit_refund_id')) {
                $table->dropColumn('xendit_refund_id');
            }
            if (Schema::hasColumn('payments', 'refund_status')) {
                $table->dropColumn('refund_status');
            }
        });
    }
};
