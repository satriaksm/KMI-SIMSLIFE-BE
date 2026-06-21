<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->string('xendit_invoice_id')->nullable()->unique();
            $table->string('payment_method', 100)->nullable()->comment('Channel aktual dari Xendit: QRIS, BCA, BRI, dll.');
            $table->string('status', 50)->default('PENDING')->comment('PENDING | PAID | EXPIRED | FAILED');
            $table->bigInteger('amount')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_channel', 100)->nullable()->comment('Channel yang dipakai saat bayar');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('xendit_invoice_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        // Do nothing - don't drop payments table
    }
};