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
        Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('post_title');
            $table->text('post_content');
            $table->string('post_slug')->unique();
            $table->enum('post_status', ['draft', 'published', 'archived'])->default('published');
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index('post_status');
            $table->index('created_at');
            $table->index('post_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_posts');
    }
};
