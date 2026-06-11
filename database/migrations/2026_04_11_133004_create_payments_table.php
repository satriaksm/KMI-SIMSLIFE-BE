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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('external_id')->unique(); // order-123
            $table->string('xendit_invoice_id')->nullable();
            $table->string('xendit_refund_id')->nullable();
            $table->string('invoice_url')->nullable();
            $table->string('payment_method')->nullable(); // VA, QRIS, dll
            $table->timestamp('expired_at')->nullable();
            $table->decimal('amount', 15, 2);

            $table->enum('status', [
                'pending',
                'paid',
                'expired',
                'failed'
            ])->default('pending');

            $table->string('refund_status')->nullable()->comment('processing, succeeded, failed, resolved');


            $table->timestamp('paid_at')->nullable();

            $table->json('raw_response')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};