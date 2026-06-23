<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('paguyuban_id')
                ->nullable()
                ->constrained('paguyubans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('segmentation_id')
                ->constrained('segmentations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name');

            $table->string('slug')
                ->unique()
                ->index();

            $table->text('description')->nullable();

            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();

            $table->string('phone')->nullable();

            $table->json('operational_hours')->nullable();

            // Updated enum
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'suspended',
                'archived'
            ])->default('pending');

            $table->text('rejection_reason')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('response_at')->nullable();
            $table->string('NPWP')->unique()->nullable();
            $table->string('bank_code')->nullable(); // BCA, BRI (WAJIB untuk Xendit)
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->decimal('balance_available', 15, 2)->default(0);
            $table->decimal('balance_pending', 15, 2)->default(0);
            $table->timestamp('last_payout_at')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};