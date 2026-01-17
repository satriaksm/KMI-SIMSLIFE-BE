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
        Schema::table('jasas', function (Blueprint $table) {
            // Add indexes untuk performance query
            $table->index('is_active');
            $table->index('price');
            $table->index(['merchant_id', 'is_active']); // composite index untuk query filtering
            $table->index('title'); // untuk search
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasas', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['price']);
            $table->dropIndex(['merchant_id', 'is_active']);
            $table->dropIndex(['title']);
        });
    }
};
