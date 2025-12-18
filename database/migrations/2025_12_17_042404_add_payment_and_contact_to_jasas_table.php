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
            // Payment methods (COD, QRIS, etc)
            if (!Schema::hasColumn('jasas', 'payment_methods')) {
                $table->string('payment_methods')->default('cod')->comment('Comma-separated: cod,qris');
            }
            
            // WhatsApp link for customer contact
            if (!Schema::hasColumn('jasas', 'whatsapp_link')) {
                $table->string('whatsapp_link')->nullable()->comment('WhatsApp link for customer contact');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jasas', function (Blueprint $table) {
            if (Schema::hasColumn('jasas', 'payment_methods')) {
                $table->dropColumn('payment_methods');
            }
            if (Schema::hasColumn('jasas', 'whatsapp_link')) {
                $table->dropColumn('whatsapp_link');
            }
        });
    }
};
