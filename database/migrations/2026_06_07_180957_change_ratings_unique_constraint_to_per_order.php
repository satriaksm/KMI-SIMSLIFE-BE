<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hapus unique constraint lama: 1 user hanya bisa rating 1x per produk
        try {
            Schema::table('ratings', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'rateable_id', 'rateable_type']);
            });
        } catch (\Exception $e) {
            // Index might already be dropped or named differently
        }

        try {
            Schema::table('ratings', function (Blueprint $table) {
                $table->dropUnique('ratings_user_id_rateable_id_rateable_type_unique');
            });
        } catch (\Exception $e) {
            // Ignore if index doesn't exist
        }

        // Tambah unique constraint baru: 1 user hanya bisa rating 1x per ORDER per produk
        // Sehingga jika user order produk yang sama 2x dari order berbeda, bisa review keduanya
        try {
            Schema::table('ratings', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'order_id', 'rateable_id', 'rateable_type'],
                    'ratings_user_order_rateable_unique'
                );
            });
        } catch (\Exception $e) {
            // Ignore if index already exists
        }
    }

    public function down(): void
    {
        try {
            Schema::table('ratings', function (Blueprint $table) {
                $table->dropUnique('ratings_user_order_rateable_unique');
            });
        } catch (\Exception $e) {
            // Ignore if index doesn't exist
        }

        try {
            Schema::table('ratings', function (Blueprint $table) {
                $table->unique(['user_id', 'rateable_id', 'rateable_type']);
            });
        } catch (\Exception $e) {
            // Ignore if index already exists
        }
    }
};
