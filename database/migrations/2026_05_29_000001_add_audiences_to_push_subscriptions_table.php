<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->json('audiences')->nullable()->after('user_id');
        });

        // Langganan lama: tetap terima semua sampai user aktifkan ulang per peran
        DB::table('push_subscriptions')
            ->whereNull('audiences')
            ->update(['audiences' => json_encode(['customer', 'merchant'])]);
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('audiences');
        });
    }
};
