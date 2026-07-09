<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_fees', function (Blueprint $table) {
            $table->id();
            $table->string('method_code', 50)->comment('VA, QRIS, EWALLET, SHOPEEPAY, RETAIL');
            $table->string('method_name', 100)->comment('Nama lengkap metode pembayaran');
            $table->enum('type', ['percentage', 'flat'])->default('flat');
            $table->decimal('value', 10, 2)->comment('Fee value: percentage (2.5) or flat amount (4440)');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('method_code', 'payment_fees_method_code_unique');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_fees');
    }
};
