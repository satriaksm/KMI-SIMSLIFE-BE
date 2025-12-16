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
            // 1. Kategori & Sub Kategori
            if (!Schema::hasColumn('jasas', 'jasa_category_id')) {
                $table->foreignId('jasa_category_id')->nullable()->constrained('jasa_categories')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('jasas', 'jasa_subcategory_id')) {
                $table->foreignId('jasa_subcategory_id')->nullable()->constrained('jasa_subcategories')->cascadeOnDelete();
            }

            // 2. Pengaturan Harga
            if (!Schema::hasColumn('jasas', 'price_type')) {
                $table->enum('price_type', ['per_jam', 'per_sesi', 'per_hari', 'per_project'])->default('per_sesi');
            }
            if (!Schema::hasColumn('jasas', 'base_price')) {
                $table->integer('base_price')->default(0);
            }
            if (!Schema::hasColumn('jasas', 'min_order')) {
                $table->integer('min_order')->default(1);
            }
            if (!Schema::hasColumn('jasas', 'negotiable')) {
                $table->boolean('negotiable')->default(false);
            }

            // 3. Durasi & Waktu Layanan
            if (!Schema::hasColumn('jasas', 'estimated_duration')) {
                $table->string('estimated_duration')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'operating_hours_start')) {
                $table->time('operating_hours_start')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'operating_hours_end')) {
                $table->time('operating_hours_end')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'operating_days')) {
                $table->string('operating_days')->default('1,2,3,4,5,6,7');
            }
            if (!Schema::hasColumn('jasas', 'booking_advance_days')) {
                $table->integer('booking_advance_days')->default(0);
            }

            // 4. Lokasi & Area Layanan
            if (!Schema::hasColumn('jasas', 'service_type')) {
                $table->enum('service_type', ['on_site', 'at_location', 'online'])->default('at_location');
            }
            if (!Schema::hasColumn('jasas', 'location_address')) {
                $table->text('location_address')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'service_area')) {
                $table->text('service_area')->nullable();
            }

            // 5. Kapasitas & Batasan
            if (!Schema::hasColumn('jasas', 'capacity_per_slot')) {
                $table->integer('capacity_per_slot')->default(1);
            }
            if (!Schema::hasColumn('jasas', 'max_orders_per_day')) {
                $table->integer('max_orders_per_day')->nullable();
            }

            // 7. Syarat & Ketentuan
            if (!Schema::hasColumn('jasas', 'cancellation_policy')) {
                $table->text('cancellation_policy')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'customer_requirements')) {
                $table->text('customer_requirements')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'special_notes')) {
                $table->text('special_notes')->nullable();
            }

            // 8. Media Pendukung
            if (!Schema::hasColumn('jasas', 'portfolio')) {
                $table->text('portfolio')->nullable();
            }
            if (!Schema::hasColumn('jasas', 'social_media')) {
                $table->text('social_media')->nullable();
            }

            // 9. Info Admin
            if (!Schema::hasColumn('jasas', 'status')) {
                $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');
            }
            if (!Schema::hasColumn('jasas', 'internal_code')) {
                $table->string('internal_code')->nullable()->unique();
            }
            if (!Schema::hasColumn('jasas', 'priority')) {
                $table->integer('priority')->default(0);
            }
            if (!Schema::hasColumn('jasas', 'is_featured')) {
                $table->boolean('is_featured')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasas', function (Blueprint $table) {
            if (Schema::hasColumn('jasas', 'jasa_category_id')) {
                $table->dropForeign(['jasa_category_id']);
                $table->dropColumn('jasa_category_id');
            }
            if (Schema::hasColumn('jasas', 'jasa_subcategory_id')) {
                $table->dropForeign(['jasa_subcategory_id']);
                $table->dropColumn('jasa_subcategory_id');
            }
            $columns = ['price_type', 'base_price', 'min_order', 'negotiable', 'estimated_duration', 'operating_hours_start', 'operating_hours_end', 'operating_days', 'booking_advance_days', 'service_type', 'location_address', 'service_area', 'capacity_per_slot', 'max_orders_per_day', 'cancellation_policy', 'customer_requirements', 'special_notes', 'portfolio', 'social_media', 'status', 'internal_code', 'priority', 'is_featured'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('jasas', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
