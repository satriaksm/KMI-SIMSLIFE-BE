<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'address_id')) {
                $table->foreignId('address_id')->nullable()->after('id')->constrained('addresses')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'voucher_id')) {
                $table->foreignId('voucher_id')->nullable()->after('merchant_id')->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'order_code')) {
                $table->string('order_code')->nullable()->after('order_type');
            }
            if (!Schema::hasColumn('orders', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->default(0)->after('total_price');
            }
            if (!Schema::hasColumn('orders', 'discount_total')) {
                $table->decimal('discount_total', 12, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('orders', 'delivery_type')) {
                $table->enum('delivery_type', ['pickup', 'delivery'])->default('pickup')->after('discount_total');
            }
            if (!Schema::hasColumn('orders', 'delivery_fee_snapshot')) {
                $table->decimal('delivery_fee_snapshot', 12, 2)->default(0)->after('payment_status');
            }
            if (!Schema::hasColumn('orders', 'platform_fee')) {
                $table->decimal('platform_fee', 12, 2)->default(0)->after('delivery_fee_snapshot');
            }
            if (!Schema::hasColumn('orders', 'gross_amount')) {
                $table->decimal('gross_amount', 12, 2)->default(0)->after('platform_fee');
            }
            if (!Schema::hasColumn('orders', 'net_amount')) {
                $table->decimal('net_amount', 12, 2)->default(0)->after('gross_amount');
            }
            if (!Schema::hasColumn('orders', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
            if (!Schema::hasColumn('orders', 'user_name_snapshot')) {
                $table->string('user_name_snapshot')->nullable()->after('confirm_deadline');
            }
            if (!Schema::hasColumn('orders', 'user_phone_snapshot')) {
                $table->string('user_phone_snapshot')->nullable()->after('user_name_snapshot');
            }
            if (!Schema::hasColumn('orders', 'address_detail_snapshot')) {
                $table->text('address_detail_snapshot')->nullable()->after('user_phone_snapshot');
            }
            if (!Schema::hasColumn('orders', 'province_name_snapshot')) {
                $table->string('province_name_snapshot')->nullable()->after('address_detail_snapshot');
            }
            if (!Schema::hasColumn('orders', 'city_name_snapshot')) {
                $table->string('city_name_snapshot')->nullable()->after('province_name_snapshot');
            }
            if (!Schema::hasColumn('orders', 'district_name_snapshot')) {
                $table->string('district_name_snapshot')->nullable()->after('city_name_snapshot');
            }
            if (!Schema::hasColumn('orders', 'village_name_snapshot')) {
                $table->string('village_name_snapshot')->nullable()->after('district_name_snapshot');
            }
            if (!Schema::hasColumn('orders', 'latitude_snapshot')) {
                $table->decimal('latitude_snapshot', 10, 7)->nullable()->after('village_name_snapshot');
            }
            if (!Schema::hasColumn('orders', 'longitude_snapshot')) {
                $table->decimal('longitude_snapshot', 10, 7)->nullable()->after('latitude_snapshot');
            }
            if (!Schema::hasColumn('orders', 'proof_image_path')) {
                $table->string('proof_image_path')->nullable()->after('longitude_snapshot');
            }
            if (!Schema::hasColumn('orders', 'failed_reason')) {
                $table->string('failed_reason')->nullable()->after('proof_image_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'address_id')) {
                $table->dropForeign(['address_id']);
                $table->dropColumn('address_id');
            }
            if (Schema::hasColumn('orders', 'voucher_id')) {
                $table->dropForeign(['voucher_id']);
                $table->dropColumn('voucher_id');
            }
            $cols = [
                'order_code', 'subtotal', 'discount_total', 'delivery_type',
                'delivery_fee_snapshot', 'platform_fee', 'gross_amount', 'net_amount',
                'notes', 'user_name_snapshot', 'user_phone_snapshot', 'address_detail_snapshot',
                'province_name_snapshot', 'city_name_snapshot', 'district_name_snapshot',
                'village_name_snapshot', 'latitude_snapshot', 'longitude_snapshot',
                'proof_image_path', 'failed_reason'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
