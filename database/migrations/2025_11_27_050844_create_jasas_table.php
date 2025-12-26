<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jasas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->string('title');
            $table->string('vendor')->nullable();
            $table->integer('price')->default(0);
            $table->string('image')->nullable();
            $table->float('rating', 3, 1)->default(0);
            $table->float('distance_km', 5, 2)->nullable();
            $table->float('duration_hours', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasas');
    }
};
