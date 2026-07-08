<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            
            $table->enum('action_type', [
                'status_change',
                'warn_user',
                'suspend_user',
                'unsuspend_user',
                'limit_posting',
                'remove_limit',
                'invite_event',
                'send_notification',
                'assign_case',
                'resolve_report',
                'bulk_update',
                'manual_override'
            ]);
            $table->morphs('target');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable(); 
            $table->string('status_before')->nullable();
            $table->string('status_after')->nullable();
            $table->timestamps();
            
            $table->index('action_type');
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};