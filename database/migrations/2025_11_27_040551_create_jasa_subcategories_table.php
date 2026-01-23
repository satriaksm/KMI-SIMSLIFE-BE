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
        Schema::create('jasa_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jasa_category_id')->constrained('jasa_categories')->cascadeOnDelete();
            $table->string('name'); // Bahasa Inggris, Matematika, Online/Offline
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jasa_subcategories');
    }
};
