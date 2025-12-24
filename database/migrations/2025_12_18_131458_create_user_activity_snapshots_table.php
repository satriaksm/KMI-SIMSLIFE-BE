<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('period', 7);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('total_activity')->default(0); 
            $table->decimal('activity_score', 8, 2)->default(0);
            $table->unsignedInteger('population_avg_activity')->default(0);
            $table->timestamps();
            
            $table->unique(['user_id', 'period']);
            $table->index('period');
            $table->index(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_snapshots');
    }
};