<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_variant_option_values', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_variant_id');
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedInteger('product_option_value_id');
            $table->foreign('product_option_value_id')
                ->references('id')
                ->on('product_option_values')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->timestamps();

            // Primary lookup: variant → values
            $table->index('product_variant_id', 'pvov_variant_idx');

            // Reverse lookup: value → variants (untuk cek usage)
            $table->index('product_option_value_id', 'pvov_value_idx');

            // Composite unique untuk prevent duplikat kombinasi
            $table->unique(['product_variant_id', 'product_option_value_id'], 'pvov_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_option_values');
    }
};