<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table)
        {
            $table->id();
            $table->morphs('imageable'); // sudah auto-index imageable_type + imageable_id
            $table->string('image_path');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();

            // Composite index untuk query cover image cepat
            $table->index(['imageable_type', 'imageable_id', 'is_cover'], 'images_cover_idx');

            // Index untuk order by display_order
            $table->index(['imageable_type', 'imageable_id', 'display_order'], 'images_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};