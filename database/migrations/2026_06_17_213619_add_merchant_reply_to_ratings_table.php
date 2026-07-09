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
        Schema::table('ratings', function (Blueprint $table) {
            // Check if ratings table exists
            if (!Schema::hasTable('ratings')) {
                return;
            }

            // Add merchant_reply column if not exists
            if (!Schema::hasColumn('ratings', 'merchant_reply')) {
                $table->text('merchant_reply')->nullable()->after('comment');
            }

            // Add merchant_reply_at column if not exists
            if (!Schema::hasColumn('ratings', 'merchant_reply_at')) {
                $table->timestamp('merchant_reply_at')->nullable()->after('merchant_reply');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (Schema::hasColumn('ratings', 'merchant_reply_at')) {
                $table->dropColumn('merchant_reply_at');
            }

            if (Schema::hasColumn('ratings', 'merchant_reply')) {
                $table->dropColumn('merchant_reply');
            }
        });
    }
};
