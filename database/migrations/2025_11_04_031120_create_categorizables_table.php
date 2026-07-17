<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorizables', function (Blueprint $table)
        {
            $table->id();
            $table->unsignedSmallInteger('category_id');
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->morphs('categorizable'); // auto-index categorizable_type + categorizable_id
            $table->timestamps();

            // Unique untuk prevent duplikat category per entity
            $table->unique(['categorizable_id', 'categorizable_type', 'category_id'], 'categorizables_unique');

            // Reverse lookup: entity → categories (sudah di-cover morphs, tapi tambah untuk explicit)
            $table->index(['categorizable_type', 'categorizable_id', 'category_id'], 'categorizables_morph_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorizables');
    }
};