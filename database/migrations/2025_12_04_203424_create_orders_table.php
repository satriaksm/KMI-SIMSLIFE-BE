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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_code');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->enum('delivery_type', ['pickup', 'delivery'])->default('pickup');
            $table->decimal('delivery_fee_snapshot', 12, 2)->default(0);
            $table->enum('status', ['pending', 'responsed', 'paid', 'delivered', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('responsed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('user_name_snapshot');
            $table->string('user_phone_snapshot');
            $table->text('address_detail_snapshot');
            $table->string('province_name_snapshot');
            $table->string('city_name_snapshot');
            $table->string('district_name_snapshot');
            $table->string('village_name_snapshot');
            $table->decimal('latitude_snapshot', 10, 7)->nullable();
            $table->decimal('longitude_snapshot', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
