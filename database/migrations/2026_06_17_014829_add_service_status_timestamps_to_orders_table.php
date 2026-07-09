<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add service status timestamps to orders table.
     * Uses Schema::hasColumn to safely check if columns exist before adding.
     */
    public function up(): void
    {
        // Check if orders table exists
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) use (&$columnsToAdd) {
            $columnsToAdd = [];

            // Add responsed_at - timestamp when merchant first responds to order
            if (!Schema::hasColumn('orders', 'responsed_at')) {
                $columnsToAdd[] = 'responsed_at';
                $table->timestamp('responsed_at')->nullable()->after('status');
            }

            // Add accepted_at - timestamp when order is accepted by merchant
            if (!Schema::hasColumn('orders', 'accepted_at')) {
                $columnsToAdd[] = 'accepted_at';
                $table->timestamp('accepted_at')->nullable()->after('responsed_at');
            }

            // Add rejected_at - timestamp when order is rejected by merchant
            if (!Schema::hasColumn('orders', 'rejected_at')) {
                $columnsToAdd[] = 'rejected_at';
                $table->timestamp('rejected_at')->nullable()->after('accepted_at');
            }

            // Add started_at - timestamp when merchant starts working on order
            if (!Schema::hasColumn('orders', 'started_at')) {
                $columnsToAdd[] = 'started_at';
                $table->timestamp('started_at')->nullable()->after('rejected_at');
            }

            // Add delivered_at - timestamp when service is delivered/waiting confirmation
            if (!Schema::hasColumn('orders', 'delivered_at')) {
                $columnsToAdd[] = 'delivered_at';
                $table->timestamp('delivered_at')->nullable()->after('started_at');
            }

            // Add completed_at - timestamp when order is completed
            if (!Schema::hasColumn('orders', 'completed_at')) {
                $columnsToAdd[] = 'completed_at';
                $table->timestamp('completed_at')->nullable()->after('delivered_at');
            }

            // Add cancelled_at - timestamp when order is cancelled
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $columnsToAdd[] = 'cancelled_at';
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            }

            // Log which columns were added
            if (!empty($columnsToAdd)) {
                \Illuminate\Support\Facades\Log::info('[Migration] Adding timestamp columns to orders table: ' . implode(', ', $columnsToAdd));
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $columnsToRemove = [];

            if (Schema::hasColumn('orders', 'responsed_at')) {
                $columnsToRemove[] = 'responsed_at';
                $table->dropColumn('responsed_at');
            }

            if (Schema::hasColumn('orders', 'accepted_at')) {
                $columnsToRemove[] = 'accepted_at';
                $table->dropColumn('accepted_at');
            }

            if (Schema::hasColumn('orders', 'rejected_at')) {
                $columnsToRemove[] = 'rejected_at';
                $table->dropColumn('rejected_at');
            }

            if (Schema::hasColumn('orders', 'started_at')) {
                $columnsToRemove[] = 'started_at';
                $table->dropColumn('started_at');
            }

            if (Schema::hasColumn('orders', 'delivered_at')) {
                $columnsToRemove[] = 'delivered_at';
                $table->dropColumn('delivered_at');
            }

            if (Schema::hasColumn('orders', 'completed_at')) {
                $columnsToRemove[] = 'completed_at';
                $table->dropColumn('completed_at');
            }

            if (Schema::hasColumn('orders', 'cancelled_at')) {
                $columnsToRemove[] = 'cancelled_at';
                $table->dropColumn('cancelled_at');
            }

            if (!empty($columnsToRemove)) {
                \Illuminate\Support\Facades\Log::info('[Migration] Removing timestamp columns from orders table: ' . implode(', ', $columnsToRemove));
            }
        });
    }
};
