<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->enum('sender_role', ['buyer', 'merchant']);
            $table->enum('type', ['text', 'offer'])->default('text');
            $table->longText('body');
            $table->foreignId('jasa_id')->nullable()->constrained('jasas')->onDelete('set null');
            $table->decimal('offer_price', 15, 2)->nullable();
            $table->enum('offer_status', ['pending', 'accepted', 'rejected'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
