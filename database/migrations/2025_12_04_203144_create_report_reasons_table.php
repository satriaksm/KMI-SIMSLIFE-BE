<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_reasons', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('reason_title', 255);
            $table->text('reason_description')->nullable();
            $table->enum('applies_to', ['product', 'service', 'merchant', 'post', 'post_comment', 'user']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_reasons');
    }
};