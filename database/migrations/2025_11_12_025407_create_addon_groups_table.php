<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addon_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('addon_group_name', 100);
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->unsignedTinyInteger('min_selection')->default(0);
            $table->unsignedTinyInteger('max_selection')->nullable();
            $table->timestamps();

            // Index untuk list groups by product
            $table->index('product_id', 'addon_groups_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_groups');
    }
};