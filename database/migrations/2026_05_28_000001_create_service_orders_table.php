<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Temporary flow without payment gateway.
     * Order is considered created after WhatsApp redirect.
     * Future implementation will use payment gateway callback.
     */
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('jasa_id')->constrained('jasas')->cascadeOnDelete();
            $table->unsignedBigInteger('consultation_id')->nullable();
            
            $table->string('order_number')->unique()->nullable();
            $table->string('service_name');
            $table->string('service_type')->nullable();
            $table->string('service_image')->nullable();
            $table->string('merchant_name');
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('status')->default('menunggu_konfirmasi_merchant');
            
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            $table->date('booking_date')->nullable();
            $table->time('booking_time')->nullable();
            $table->text('booking_note')->nullable();
            $table->string('mekanisme_pemesanan')->nullable();
            $table->text('completion_note')->nullable();
            
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->decimal('customer_latitude', 10, 8)->nullable();
            $table->decimal('customer_longitude', 11, 8)->nullable();
            $table->text('service_location_address')->nullable();
            
            $table->text('whatsapp_redirect_url')->nullable();
            
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('UNPAID');
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('review_id')->nullable()->constrained('ratings')->nullOnDelete();
            
            $table->boolean('customer_confirmed')->default(false);
            $table->timestamp('customer_confirmed_at')->nullable();

            $table->timestamps();

            // Indexes for faster queries
            $table->index('customer_id');
            $table->index('merchant_id');
            $table->index('status');
            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
        });
        Schema::table('ratings', function (Blueprint $table) {
            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (Schema::hasColumn('ratings', 'service_order_id')) {
                $table->dropForeign(['service_order_id']);
            }
        });
        Schema::dropIfExists('service_orders');
    }
};