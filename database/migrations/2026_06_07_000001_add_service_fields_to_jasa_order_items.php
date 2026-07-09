<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add service-specific fields to jasa_order_items for unified order system.
     * These fields were previously in service_orders table.
     */
    public function up(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            // Booking info
            if (!Schema::hasColumn('jasa_order_items', 'booking_note')) {
                $table->text('booking_note')->nullable()->after('booking_time');
            }

            if (!Schema::hasColumn('jasa_order_items', 'service_location_address')) {
                $table->string('service_location_address', 500)->nullable()->after('booking_note');
            }

            // Customer location for on-site services
            if (!Schema::hasColumn('jasa_order_items', 'customer_latitude')) {
                $table->decimal('customer_latitude', 10, 8)->nullable()->after('service_location_address');
            }

            if (!Schema::hasColumn('jasa_order_items', 'customer_longitude')) {
                $table->decimal('customer_longitude', 11, 8)->nullable()->after('customer_latitude');
            }

            // WhatsApp redirect for manual payment confirmation
            if (!Schema::hasColumn('jasa_order_items', 'whatsapp_redirect_url')) {
                $table->string('whatsapp_redirect_url', 500)->nullable()->after('customer_longitude');
            }

            // Completion info
            if (!Schema::hasColumn('jasa_order_items', 'completion_note')) {
                $table->text('completion_note')->nullable()->after('whatsapp_redirect_url');
            }

            if (!Schema::hasColumn('jasa_order_items', 'customer_confirmed')) {
                $table->boolean('customer_confirmed')->default(false)->after('completion_note');
            }

            if (!Schema::hasColumn('jasa_order_items', 'customer_confirmed_at')) {
                $table->timestamp('customer_confirmed_at')->nullable()->after('customer_confirmed');
            }

            // Review tracking
            if (!Schema::hasColumn('jasa_order_items', 'is_reviewed')) {
                $table->boolean('is_reviewed')->default(false)->after('customer_confirmed_at');
            }

            if (!Schema::hasColumn('jasa_order_items', 'review_id')) {
                $table->foreignId('review_id')->nullable()->after('is_reviewed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            $columns = [
                'booking_note',
                'service_location_address',
                'customer_latitude',
                'customer_longitude',
                'whatsapp_redirect_url',
                'completion_note',
                'customer_confirmed',
                'customer_confirmed_at',
                'is_reviewed',
                'review_id',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('jasa_order_items', $column)) {
                    if ($column === 'review_id') {
                        $table->dropForeignIdFor('review_id');
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
