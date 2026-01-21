<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('jasas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');

            // Kategori & Sub Kategori
            $table->foreignId('jasa_category_id')->nullable()->constrained('jasa_categories')->cascadeOnDelete();
            $table->foreignId('jasa_subcategory_id')->nullable()->constrained('jasa_subcategories')->cascadeOnDelete();

            $table->string('title');
            $table->string('vendor')->nullable();

            // Pengaturan Harga
            $table->integer('price')->default(0);
            $table->enum('price_type', ['per_jam', 'per_sesi', 'per_hari', 'per_project'])->default('per_sesi');
            $table->integer('fixed_price')->default(0);
            $table->integer('base_price')->default(0);
            $table->integer('min_order')->default(1);
            $table->boolean('negotiable')->default(false);

            // Media
            $table->string('image')->nullable();
            $table->text('portfolio')->nullable();
            $table->text('social_media')->nullable();

            // Rating & Durasi
            $table->float('rating', 3, 1)->default(0);
            $table->float('distance_km', 5, 2)->nullable();
            $table->float('duration_hours', 5, 2)->nullable();
            $table->string('estimated_duration')->nullable();

            // Durasi & Waktu Layanan
            $table->time('operating_hours_start')->nullable();
            $table->time('operating_hours_end')->nullable();
            $table->string('operating_days')->default('1,2,3,4,5,6,7');
            $table->string('operating_times')->nullable();
            $table->integer('booking_advance_days')->default(0);

            // Lokasi & Area Layanan
            $table->enum('service_type', ['on_site', 'at_location', 'online'])->default('at_location');
            $table->text('location_address')->nullable();
            $table->text('service_area')->nullable();

            // Kapasitas & Batasan
            $table->integer('capacity_per_slot')->default(1);
            $table->integer('max_orders_per_day')->nullable();

            // Deskripsi & Catatan
            $table->text('description')->nullable();
            $table->text('special_notes')->nullable();
            $table->text('cancellation_policy')->nullable();
            $table->text('customer_requirements')->nullable();

            // Payment & Kontak
            $table->string('payment_methods')->default('cod')->comment('Comma-separated: cod,qris');
            $table->string('whatsapp_link')->nullable()->comment('WhatsApp link for customer contact');

            // Info Admin
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');
            $table->string('internal_code')->nullable()->unique();
            $table->integer('priority')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index('is_active');
            $table->index('price');
            $table->index(['merchant_id', 'is_active']);
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jasas');
    }
};