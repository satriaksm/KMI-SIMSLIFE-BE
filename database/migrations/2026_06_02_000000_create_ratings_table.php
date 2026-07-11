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
            $table->foreignId('product_order_item_id')->nullable()->constrained('product_order_items')->cascadeOnDelete();
            $table->foreignId('jasa_order_item_id')->nullable()->constrained('jasa_order_items')->cascadeOnDelete();
            
            // Polymorphic untuk Product atau Jasa
            $table->morphs('rateable');
            
            $table->integer('rating')->unsigned(); // 1-5
            $table->string('title')->nullable(); // Judul review
            $table->text('comment')->nullable(); // Komentar review
            $table->boolean('is_anonymous')->default(false);
            $table->text('merchant_reply')->nullable();
            $table->timestamp('merchant_reply_at')->nullable();
            $table->unsignedInteger('update_count')->default(0);
            $table->timestamp('review_updated_at')->nullable();
            
            $table->timestamps();
            
            // Unique constraint: 1 pembeli hanya bisa rating 1x per ORDER per produk
            $table->unique(
                ['user_id', 'order_id', 'rateable_id', 'rateable_type'],
                'ratings_user_order_rateable_unique'
            );
            // Unique constraint for product order
            $table->unique(['user_id', 'product_order_item_id'], 'ratings_user_product_order_unique');
            // Unique constraint for jasa order
            $table->unique(['user_id', 'jasa_order_item_id'], 'ratings_user_jasa_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
