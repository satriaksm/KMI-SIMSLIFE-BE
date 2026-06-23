<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Chat messages for consultations with media support.
     * Used by both customer and merchant.
     */
    public function up(): void
    {
        // Consultation messages table
        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_consultation_id')
                ->constrained('service_consultations')->onDelete('cascade');
            $table->foreignId('sender_id')
                ->constrained('users')->onDelete('cascade');
            $table->string('sender_type', 20);  // customer | merchant
            $table->text('message')->nullable();  // Text message (optional if media exists)
            $table->string('message_type', 30)->default('text'); // text | proposal | rejection | acceptance
            $table->decimal('proposed_price', 12, 2)->nullable();  // Proposed price if any
            $table->timestamps();

            $table->index(['service_consultation_id', 'created_at']);
        });

        // Media attachments for consultation messages
        Schema::create('consultation_message_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_message_id')
                ->constrained('consultation_messages')->onDelete('cascade');
            $table->string('file_name');  // Original filename
            $table->string('file_path');  // Storage path
            $table->string('file_url')->nullable();  // Full public URL
            $table->string('file_type', 20);  // image | video
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->default(0);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['consultation_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_message_media');
        Schema::dropIfExists('consultation_messages');
    }
};
