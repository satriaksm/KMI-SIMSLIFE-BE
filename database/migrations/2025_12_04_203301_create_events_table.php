<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('event_name', 255);
            $table->text('event_description')->nullable();
            $table->date('event_start_date');
            $table->date('event_end_date');
            $table->string('banner_img_path')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index('status');
            $table->index(['event_start_date', 'event_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};