<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Snapshot fields to preserve service (jasa) data at the time of purchase.
     * These fields capture the state of jasa at order time.
     * If merchant modifies the jasa after order, historical data remains intact.
     *
     * IMPORTANT: Always read from snapshot fields when displaying order history.
     * Only fall back to live jasa data if snapshot is empty (legacy orders).
     */
    public function up(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            // Service snapshot (jasa info at time of purchase)
            $table->string('jasa_title_snapshot')->nullable()->after('service_consultation_id');
            $table->text('jasa_description_snapshot')->nullable()->after('jasa_title_snapshot');
            $table->string('jasa_image_snapshot')->nullable()->after('jasa_description_snapshot');

            // Price snapshot
            $table->decimal('jasa_price_snapshot', 14, 2)->nullable()->after('jasa_image_snapshot');
            $table->decimal('original_price_snapshot', 14, 2)->nullable()->after('jasa_price_snapshot');
            $table->decimal('offered_price_snapshot', 14, 2)->nullable()->after('original_price_snapshot');
            $table->decimal('agreed_price_snapshot', 14, 2)->nullable()->after('offered_price_snapshot');

            // Service type snapshot
            $table->string('service_type_snapshot')->nullable()->after('agreed_price_snapshot');
            $table->string('booking_type_snapshot')->nullable()->after('service_type_snapshot');

            // Booking snapshot (from consultation proposal)
            $table->date('booking_date_snapshot')->nullable()->after('booking_type_snapshot');
            $table->string('booking_time_snapshot')->nullable()->after('booking_date_snapshot');
            $table->text('customer_note_snapshot')->nullable()->after('booking_time_snapshot');

            // Offer details snapshot (from consultation)
            $table->text('offer_note_snapshot')->nullable()->after('customer_note_snapshot');
            $table->timestamp('agreed_at')->nullable()->after('offer_note_snapshot');

            // Merchant snapshot
            $table->string('merchant_name_snapshot')->nullable()->after('agreed_at');
            $table->string('merchant_phone_snapshot')->nullable()->after('merchant_name_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasa_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'jasa_title_snapshot',
                'jasa_description_snapshot',
                'jasa_image_snapshot',
                'jasa_price_snapshot',
                'original_price_snapshot',
                'offered_price_snapshot',
                'agreed_price_snapshot',
                'service_type_snapshot',
                'booking_type_snapshot',
                'booking_date_snapshot',
                'booking_time_snapshot',
                'customer_note_snapshot',
                'offer_note_snapshot',
                'agreed_at',
                'merchant_name_snapshot',
                'merchant_phone_snapshot',
            ]);
        });
    }
};
