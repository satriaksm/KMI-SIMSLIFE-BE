<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make legacy order fields nullable to support product/jasa checkout flows
     * where snapshot fields are used instead of old nama/tel/alamat fields.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'nama')) {
                $table->string('nama')->nullable()->change();
            }

            if (Schema::hasColumn('orders', 'tel')) {
                $table->string('tel')->nullable()->change();
            }

            if (Schema::hasColumn('orders', 'alamat')) {
                $table->text('alamat')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'nama')) {
                $table->string('nama')->nullable(false)->change();
            }

            if (Schema::hasColumn('orders', 'tel')) {
                $table->string('tel')->nullable(false)->change();
            }

            if (Schema::hasColumn('orders', 'alamat')) {
                $table->text('alamat')->nullable(false)->change();
            }
        });
    }
};
