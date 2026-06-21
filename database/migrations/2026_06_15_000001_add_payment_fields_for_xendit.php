<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // External ID untuk Xendit reference
            if (!Schema::hasColumn('payments', 'external_id')) {
                $table->string('external_id', 150)->nullable()->after('order_id');
                $table->unique('external_id', 'payments_external_id_unique');
            }

            // Invoice URL dari Xendit
            if (!Schema::hasColumn('payments', 'invoice_url')) {
                $table->string('invoice_url', 500)->nullable()->after('xendit_invoice_id');
            }

            // Expiry datetime untuk invoice
            if (!Schema::hasColumn('payments', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('invoice_url');
            }

            // Raw response dari Xendit webhook (JSON)
            if (!Schema::hasColumn('payments', 'raw_response')) {
                $table->json('raw_response')->nullable()->after('expired_at');
            }

            // Amount dalam decimal untuk presisi
            if (Schema::hasColumn('payments', 'amount')) {
                // Konversi dari bigInteger ke decimal(15,2) jika perlu
                $table->decimal('amount', 15, 2)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'external_id')) {
                $table->dropUnique('payments_external_id_unique');
                $table->dropColumn('external_id');
            }

            if (Schema::hasColumn('payments', 'invoice_url')) {
                $table->dropColumn('invoice_url');
            }

            if (Schema::hasColumn('payments', 'expired_at')) {
                $table->dropColumn('expired_at');
            }

            if (Schema::hasColumn('payments', 'raw_response')) {
                $table->dropColumn('raw_response');
            }
        });
    }
};
