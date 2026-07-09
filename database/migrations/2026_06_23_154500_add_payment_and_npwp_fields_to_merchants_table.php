<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (!Schema::hasColumn('merchants', 'NPWP')) {
                $table->string('NPWP')->unique()->nullable()->after('response_at');
            }
            if (!Schema::hasColumn('merchants', 'bank_code')) {
                $table->string('bank_code')->nullable()->after('NPWP');
            }
            if (!Schema::hasColumn('merchants', 'bank_account_number')) {
                $table->string('bank_account_number')->nullable()->after('bank_code');
            }
            if (!Schema::hasColumn('merchants', 'bank_account_name')) {
                $table->string('bank_account_name')->nullable()->after('bank_account_number');
            }
            if (!Schema::hasColumn('merchants', 'balance_available')) {
                $table->decimal('balance_available', 15, 2)->default(0)->after('bank_account_name');
            }
            if (!Schema::hasColumn('merchants', 'balance_pending')) {
                $table->decimal('balance_pending', 15, 2)->default(0)->after('balance_available');
            }
            if (!Schema::hasColumn('merchants', 'last_payout_at')) {
                $table->timestamp('last_payout_at')->nullable()->after('balance_pending');
            }
            
            // Update status enum values natively in Laravel 12
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'suspended',
                'archived'
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'NPWP',
                'bank_code',
                'bank_account_number',
                'bank_account_name',
                'balance_available',
                'balance_pending',
                'last_payout_at'
            ]);
            
            $table->enum('status', [
                'pending',
                'approved',
                'rejected'
            ])->default('pending')->change();
        });
    }
};
