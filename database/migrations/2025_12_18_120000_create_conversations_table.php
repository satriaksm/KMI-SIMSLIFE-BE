<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buyer_id'); // user (customer)
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('jasa_id')->nullable();
            $table->string('status')->default('open'); // open, closed
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('buyer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('jasa_id')->references('id')->on('jasas')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
