<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voucher_merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('voucher_type', ['percent', 'fixed'])->nullable(); // opsional, jika merchant boleh override
            $table->decimal('discount_value', 15, 2)->nullable(); // nominal/persen diisi merchant
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['voucher_id', 'merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_merchants');
    }
};