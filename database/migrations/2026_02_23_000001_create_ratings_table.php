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
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('product_order_items')->cascadeOnDelete();
            $table->unsignedBigInteger('service_order_id')->nullable();
            
            // Polymorphic untuk Product atau Jasa
            $table->morphs('rateable');
            
            $table->integer('rating')->unsigned(); // 1-5
            $table->string('title')->nullable(); // Judul review
            $table->text('comment')->nullable(); // Komentar review
            $table->boolean('is_anonymous')->default(false);
            
            $table->timestamps();
            
            // Unique constraint: 1 pembeli hanya bisa rating 1x per ORDER per produk
            $table->unique(
                ['user_id', 'order_id', 'rateable_id', 'rateable_type'],
                'ratings_user_order_rateable_unique'
            );
            // Unique constraint for service_order_id
            $table->unique(['user_id', 'service_order_id'], 'ratings_user_service_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
