<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('option_name');
            $table->boolean('uses_image')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'option_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
    }
};