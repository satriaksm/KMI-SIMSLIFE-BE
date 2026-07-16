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
            $table->unsignedInteger('voucher_id')->nullable();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->nullOnDelete();
            $table->string('order_type', 20)->nullable();
            $table->string('order_code', 50);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->enum('delivery_type', ['pickup', 'delivery', 'online', 'on-site', 'in-store'])->default('pickup');
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_status', 30)->nullable();
            $table->decimal('delivery_fee_snapshot', 15, 2)->default(0);
            $table->decimal('platform_fee', 15, 2)->default(0);
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'accepted', 'rejected', 'on-progress', 'paid', 'delivered', 'undelivered', 'completed', 'cancelled', 'ready_to_pickup', 'unpicked'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('on_progress_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ready_to_pickup_at')->nullable();
            $table->timestamp('unpicked_at')->nullable();
            $table->timestamp('confirm_deadline')->nullable();
            $table->string('user_name_snapshot');
            $table->string('user_phone_snapshot', 20);
            $table->text('address_detail_snapshot');
            $table->string('province_name_snapshot');
            $table->string('city_name_snapshot');
            $table->string('district_name_snapshot');
            $table->string('village_name_snapshot');
            $table->decimal('latitude_snapshot', 10, 7)->nullable();
            $table->decimal('longitude_snapshot', 10, 7)->nullable();
            $table->string('proof_image_path')->nullable();
            $table->text('proof_description')->nullable();
            $table->text('failed_reason')->nullable();
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
