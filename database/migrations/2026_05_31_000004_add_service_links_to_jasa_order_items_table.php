<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('jasa_order_items', 'service_order_id')) {
                $table->foreignId('service_order_id')->nullable()->after('jasa_id')->constrained('service_orders')->nullOnDelete();
            }

            if (!Schema::hasColumn('jasa_order_items', 'service_consultation_id')) {
                $table->foreignId('service_consultation_id')->nullable()->after('service_order_id')->constrained('service_consultations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('jasa_order_items', 'service_consultation_id')) {
                $table->dropConstrainedForeignId('service_consultation_id');
            }

            if (Schema::hasColumn('jasa_order_items', 'service_order_id')) {
                $table->dropConstrainedForeignId('service_order_id');
            }
        });
    }
};