<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('posts_30d')->default(0);
            $table->unsignedInteger('comments_30d')->default(0);
            $table->unsignedInteger('orders_30d')->default(0);
            $table->unsignedInteger('total_activity_30d')->default(0);
            $table->unsignedInteger('reports_validated_30d')->default(0);
            $table->unsignedInteger('reports_total')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('last_login_days')->default(0);
            $table->decimal('activity_score', 8, 2)->default(0);
            $table->boolean('pattern_spam_detected')->default(false);
            $table->timestamps();
            
            $table->unique('user_id');
            $table->index('activity_score');
            $table->index('reports_validated_30d');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_metrics');
    }
};