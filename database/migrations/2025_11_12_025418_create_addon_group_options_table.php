<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addon_group_options', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('addon_group_id');
            $table->foreign('addon_group_id')
                ->references('id')
                ->on('addon_groups')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedInteger('addon_id');
            $table->foreign('addon_id')
                ->references('id')
                ->on('addons')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->decimal('addon_price', 10, 2)->default(0);
            $table->timestamps();

            // Unique constraint: addon tidak boleh duplikat dalam 1 group
            $table->unique(['addon_group_id', 'addon_id'], 'addon_group_option_unique');

            // Index untuk list options by group
            $table->index('addon_group_id', 'addon_group_options_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_group_options');
    }
};
