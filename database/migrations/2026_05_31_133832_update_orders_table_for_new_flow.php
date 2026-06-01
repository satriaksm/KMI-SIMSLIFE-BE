<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the enum to add accepted, rejected, undelivered
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'responsed', 'accepted', 'rejected', 'undelivered', 'paid', 'delivered', 'completed', 'cancelled') DEFAULT 'pending'");

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('responsed_at');
            $table->timestamp('rejected_at')->nullable()->after('accepted_at');
            $table->string('proof_image_path')->nullable()->after('longitude_snapshot');
            $table->string('failed_reason')->nullable()->after('proof_image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'accepted_at',
                'rejected_at',
                'proof_image_path',
                'failed_reason'
            ]);
        });
        
        // Note: It's hard to revert an ENUM column modification if values were used, 
        // but we can try reverting to the original ENUM values if needed.
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'responsed', 'paid', 'delivered', 'completed', 'cancelled') DEFAULT 'pending'");
    }
};
