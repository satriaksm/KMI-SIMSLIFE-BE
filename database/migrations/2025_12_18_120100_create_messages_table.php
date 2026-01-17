<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_id'); // users.id
            $table->string('sender_role'); // buyer, merchant, admin
            $table->string('type')->default('message'); // message, offer, system
            $table->text('body')->nullable();

            // Optional offer fields
            $table->unsignedBigInteger('jasa_id')->nullable();
            $table->integer('offer_price')->nullable();
            $table->string('offer_status')->nullable(); // pending, accepted, rejected, cancelled

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('jasa_id')->references('id')->on('jasas')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
