<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jasa_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jasa_id')->constrained('jasas')->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasa_images');
    }
};
