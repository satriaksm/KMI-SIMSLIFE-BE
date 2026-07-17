<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('merchant_id')
                ->constrained('merchants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('addon_name');
            $table->timestamps();

            // Index untuk list addons by merchant
            $table->index('merchant_id', 'addons_merchant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};