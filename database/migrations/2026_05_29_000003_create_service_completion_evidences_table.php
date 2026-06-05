<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Store work completion evidence uploaded by merchant.
     * Stored at: storage/app/public/services/completions/
     */
    public function up(): void
    {
        Schema::create('service_completion_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')
                ->constrained('service_orders')->onDelete('cascade');
            $table->string('file_name');  // Original filename
            $table->string('file_path');  // Storage path under public/services/completions/
            $table->string('file_url')->nullable();  // Full public URL via Storage::url()
            $table->string('file_type', 20);  // image | video
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->default(0);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['service_order_id']);
        });

        // Add jasa field for cara_pemesanan validation
        Schema::table('jasas', function (Blueprint $table) {
            // langsung_pesan | booking | memerlukan_konsultasi
            // This field controls which flow is used
            // - langsung_pesan: Keranjang tanpa jadwal
            // - booking: Booking dengan pilih jadwal
            // - memerlukan_konsultasi: Wajib konsultasi terlebih dahulu
            $table->string('cara_pemesanan', 30)->default('langsung_pesan')->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_completion_evidences');
        Schema::table('jasas', function (Blueprint $table) {
            $table->dropColumn('cara_pemesanan');
        });
    }
};