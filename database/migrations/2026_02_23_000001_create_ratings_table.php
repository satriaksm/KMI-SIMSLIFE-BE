<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Pemberi rating
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete(); // UMKM yang diratingkan
            
            // Polymorphic untuk Product atau Jasa
            $table->morphs('rateable');
            
            $table->integer('rating')->unsigned(); // 1-5
            $table->string('title')->nullable(); // Judul review
            $table->text('comment')->nullable(); // Komentar review
            
            $table->timestamps();
            
            // Unique constraint: 1 pembeli hanya bisa rating 1x per produk
            $table->unique(['user_id', 'rateable_id', 'rateable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
