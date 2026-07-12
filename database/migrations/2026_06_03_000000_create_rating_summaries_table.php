<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            
            // Polymorphic untuk Product atau Jasa
            $table->morphs('rateable');
            
            // Polymorphic summaryable
            $table->unsignedBigInteger('summaryable_id')->nullable();
            $table->string('summaryable_type')->nullable();
            
            // Statistik rating
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->integer('total_ratings')->default(0);
            $table->integer('total_reviews')->default(0);
            
            // Breakdown rating (berapa banyak rating per star)
            $table->integer('rating_5')->default(0);
            $table->integer('rating_4')->default(0);
            $table->integer('rating_3')->default(0);
            $table->integer('rating_2')->default(0);
            $table->integer('rating_1')->default(0);
            $table->integer('rating_5_count')->default(0);
            $table->integer('rating_4_count')->default(0);
            $table->integer('rating_3_count')->default(0);
            $table->integer('rating_2_count')->default(0);
            $table->integer('rating_1_count')->default(0);
            
            $table->timestamps();
            
            // Unique: 1 produk/jasa hanya punya 1 rating summary
            $table->unique(['merchant_id', 'rateable_id', 'rateable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_summaries');
    }
};
