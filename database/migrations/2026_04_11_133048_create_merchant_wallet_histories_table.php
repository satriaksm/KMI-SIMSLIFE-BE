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
        Schema::create('merchant_wallet_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->enum('type', [
                'credit', // masuk
                'debit'   // keluar
            ]);

            $table->decimal('amount', 15, 2);

            $table->string('reference_type'); // order / payout
            $table->unsignedBigInteger('reference_id');

            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_wallet_histories');
    }
};
