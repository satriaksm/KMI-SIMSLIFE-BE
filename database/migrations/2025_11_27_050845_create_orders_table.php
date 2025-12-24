<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->foreignId('jasa_id')->nullable()->constrained('jasas')->onDelete('cascade');
            $table->string('nama');
            $table->string('tel');
            $table->text('alamat');
            $table->text('catatan')->nullable();
            $table->string('catatan_alamat')->nullable();
            $table->date('tanggal');
            $table->string('waktu');
            $table->enum('metode_pembayaran', ['COD', 'QRIS'])->default('COD');
            $table->string('promo_code')->nullable();
            $table->integer('total')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
