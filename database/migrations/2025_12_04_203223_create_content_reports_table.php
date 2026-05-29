<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();

            // Reporter
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            // Reason
            $table->unsignedTinyInteger('report_reason_id');

            // Morph relation
            $table->morphs('reportable');

            // Report content
            $table->text('report_comment')->nullable();

            // Moderation status
            $table->enum('status', [
                'pending',
                'in_review',
                'resolved',
                'dismissed'
            ])->default('pending');

            // Moderator
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Action tracking
            $table->string('action_taken')->nullable();

            // Simpan isi original sebelum diedit admin
            $table->text('original_content')->nullable();

            // Forwarding system
            $table->foreignId('forwarded_to')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->foreignId('forwarded_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamp('forwarded_at')->nullable();
            $table->text('forward_message')->nullable();

            $table->timestamps();

            // Foreign key
            $table->foreign('report_reason_id')
                ->references('id')
                ->on('report_reasons')
                ->onDelete('cascade');

            // Indexes
            $table->index('reportable_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};