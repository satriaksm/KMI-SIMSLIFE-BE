<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_option_values', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_option_id');
            $table->foreign('product_option_id')->references('id')->on('product_options')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('option_value');
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['product_option_id', 'option_value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};