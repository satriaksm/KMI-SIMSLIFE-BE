<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // Hapus unique constraint lama: 1 user hanya bisa rating 1x per produk
            $table->dropUnique(['user_id', 'rateable_id', 'rateable_type']);

            // Tambah unique constraint baru: 1 user hanya bisa rating 1x per ORDER per produk
            // Sehingga jika user order produk yang sama 2x dari order berbeda, bisa review keduanya
            $table->unique(
                ['user_id', 'order_id', 'rateable_id', 'rateable_type'],
                'ratings_user_order_rateable_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // Rollback: kembalikan ke constraint lama
            $table->dropUnique('ratings_user_order_rateable_unique');
            $table->unique(['user_id', 'rateable_id', 'rateable_type']);
        });
    }
};
