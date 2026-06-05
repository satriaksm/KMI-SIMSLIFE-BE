<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Store consultation requests for services that require consultation.
     * Both customer and merchant can negotiate before creating a service order.
     */
    public function up(): void
    {
        Schema::create('service_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jasa_id')->constrained('jasas')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');

            // Service reference (the original jasa being consulted)
            $table->string('service_name');
            $table->decimal('original_price', 12, 2)->default(0);

            // Customer's consultation request
            $table->text('customer_description')->nullable();  // Description of needs
            $table->decimal('customer_budget', 12, 2)->nullable();  // Customer's budget
            $table->date('customer_deadline')->nullable();  // Customer's desired deadline
            $table->text('customer_note')->nullable();  // Additional notes

            // Merchant's response
            // available_options: bisa_dikerjakan | penyesuaian | tidak_bisa_dikerjakan
            $table->string('merchant_response', 30)->nullable();  // Available options
            $table->decimal('merchant_offered_price', 12, 2)->nullable();  // Price offered by merchant
            $table->text('merchant_note')->nullable();  // Notes/adjustments from merchant

            // Negotiation between customer and merchant
            $table->decimal('negotiated_price', 12, 2)->nullable();  // Final agreed price
            $table->text('negotiation_notes')->nullable();  // Both parties notes
            $table->date('agreed_deadline')->nullable();  // Agreed deadline
            
            // Booking proposal fields
            $table->date('proposed_date')->nullable();
            $table->time('proposed_time')->nullable();
            $table->text('proposed_notes')->nullable();

            // Customer confirmation of the agreement
            $table->string('offer_status', 30)->nullable();
            $table->boolean('customer_accepted')->default(false);
            $table->timestamp('customer_accepted_at')->nullable();

            // Consultation status
            // Status flow:
            // PENDING → DAPAT_DIKERJAKAN | PERLU_PENYESUAIAN | DITOLAK
            // DAPAT_DIKERJAKAN → (customer confirms) → ACCEPTTED → (service_order created)
            // PERLU_PENYESUAIAN → ACCEPTTED | DITOLAK
            // DITOLAK → (final, consultation closed)
            // ACCEPTED → (final, link to service order)
            $table->string('status', 30)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Reference to the created service order (if any)
            $table->foreignId('service_order_id')->nullable()
                ->constrained('service_orders')->onDelete('set null');

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
            $table->index(['jasa_id', 'status']);
            $table->index(['status']);
        });

        // Media attachments for consultation (photos/videos from customer)
        Schema::create('service_consultation_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_consultation_id')
                ->constrained('service_consultations')->onDelete('cascade');
            $table->string('file_name');  // Original filename
            $table->string('file_path');  // Storage path
            $table->string('file_url')->nullable();  // Full public URL
            $table->string('file_type', 20);  // image | video
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->default(0);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['service_consultation_id']);
        });

        // Consultation messages/notes (back and forth between customer and merchant)
        Schema::create('service_consultation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_consultation_id')
                ->constrained('service_consultations')->onDelete('cascade');
            $table->foreignId('sender_id')->nullable()
                ->constrained('users')->onDelete('set null');
            $table->string('sender_type', 20);  // customer | merchant | system
            $table->text('note');
            $table->timestamps();

            $table->index(['service_consultation_id']);
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreign('consultation_id')->references('id')->on('service_consultations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (Schema::hasColumn('service_orders', 'consultation_id')) {
                $table->dropForeign(['consultation_id']);
            }
        });
        Schema::dropIfExists('service_consultation_notes');
        Schema::dropIfExists('service_consultation_media');
        Schema::dropIfExists('service_consultations');
    }
};