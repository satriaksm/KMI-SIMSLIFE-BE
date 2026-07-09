<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores history of review updates.
     * When customer updates a review, old and new data are saved here.
     */
    public function up(): void
    {
        Schema::create('review_histories', function (Blueprint $table) {
            $table->id();

            // Reference to the rating/review
            $table->foreignId('rating_id')->constrained('ratings')->onDelete('cascade');

            // Old review data (before update)
            $table->unsignedTinyInteger('old_rating')->nullable();
            $table->string('old_title', 80)->nullable();
            $table->text('old_comment')->nullable();
            $table->json('old_media')->nullable(); // Snapshot of old media files

            // New review data (after update)
            $table->unsignedTinyInteger('new_rating')->nullable();
            $table->string('new_title', 80)->nullable();
            $table->text('new_comment')->nullable();
            $table->json('new_media')->nullable(); // Snapshot of new media files

            // Who made the update
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            // Index for faster lookups
            $table->index(['rating_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_histories');
    }
};
