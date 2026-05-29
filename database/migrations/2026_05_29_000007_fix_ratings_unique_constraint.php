<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix ratings unique constraint to support order-based reviews.
     *
     * Problem: Old constraint (user_id + rateable_id + rateable_type) prevents
     * user from reviewing same service in different orders.
     *
     * Solution: Replace with new constraint (user_id + service_order_id + rateable_id + rateable_type)
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // Drop the old unique constraint
            try {
                $table->dropUnique('ratings_user_id_rateable_id_rateable_type_unique');
            } catch (\Exception $e) {
                // Index might not exist, try with raw SQL
                \DB::statement("DROP INDEX ratings_user_id_rateable_id_rateable_type_unique ON ratings");
            }
        });

        // Add new unique constraint for service order based reviews
        Schema::table('ratings', function (Blueprint $table) {
            // New constraint: user can only review once per service order + rateable combination
            if (!Schema::hasIndex('ratings', 'ratings_user_service_order_rateable_unique')) {
                $table->unique(
                    ['user_id', 'service_order_id', 'rateable_id', 'rateable_type'],
                    'ratings_user_service_order_rateable_unique'
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // Drop new constraint
            if (Schema::hasIndex('ratings', 'ratings_user_service_order_rateable_unique')) {
                $table->dropUnique('ratings_user_service_order_rateable_unique');
            }

            // Restore old constraint
            if (!Schema::hasIndex('ratings', 'ratings_user_id_rateable_id_rateable_type_unique')) {
                $table->unique(['user_id', 'rateable_id', 'rateable_type'], 'ratings_user_id_rateable_id_rateable_type_unique');
            }
        });
    }
};