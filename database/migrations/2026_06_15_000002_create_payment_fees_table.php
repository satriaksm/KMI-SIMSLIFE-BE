<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_fees')) {
            Schema::create('payment_fees', function (Blueprint $table) {
                $table->id();
                $table->string('method_code', 50)->comment('VA, QRIS, EWALLET, SHOPEEPAY, RETAIL');
                $table->string('method_name', 100)->comment('Nama lengkap metode pembayaran');
                $table->enum('type', ['percentage', 'flat'])->default('flat');
                $table->decimal('value', 10, 2)->comment('Fee value: percentage (2.5) or flat amount (4440)');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('method_code', 'payment_fees_method_code_unique');
                $table->index('is_active');
            });
        } else {
            DB::statement("ALTER TABLE payment_fees MODIFY type ENUM('percentage', 'flat') NOT NULL DEFAULT 'flat'");
        }

        $this->seedDefaultFees();
    }

    public function down(): void
    {
        // No-op: table payment_fees sudah dibuat oleh migration 2026_05_29_000000_create_payment_fees_table
    }

    protected function seedDefaultFees(): void
    {
        $fees = [
            [
                'method_code' => 'VA',
                'method_name' => 'Virtual Account',
                'type' => 'flat',
                'value' => 4440,
                'description' => 'Biaya admin Virtual Account (BCA, BNI, BRI, Mandiri, dll)',
                'is_active' => true,
            ],
            [
                'method_code' => 'QRIS',
                'method_name' => 'QRIS',
                'type' => 'percentage',
                'value' => 0.70,
                'description' => 'Biaya admin QRIS (0.7% dari nominal)',
                'is_active' => true,
            ],
            [
                'method_code' => 'EWALLET',
                'method_name' => 'E-Wallet',
                'type' => 'percentage',
                'value' => 1.50,
                'description' => 'Biaya admin E-Wallet (OVO, DANA, LINKAJA - 1.5%)',
                'is_active' => true,
            ],
            [
                'method_code' => 'SHOPEEPAY',
                'method_name' => 'ShopeePay',
                'type' => 'percentage',
                'value' => 1.50,
                'description' => 'Biaya admin ShopeePay (1.5%)',
                'is_active' => true,
            ],
            [
                'method_code' => 'RETAIL',
                'method_name' => 'Convenience Store',
                'type' => 'flat',
                'value' => 5000,
                'description' => 'Biaya admin Convenience Store (Alfamart, Indomaret)',
                'is_active' => true,
            ],
        ];

        foreach ($fees as $fee) {
            DB::table('payment_fees')->updateOrInsert(
                ['method_code' => $fee['method_code']],
                array_merge($fee, [
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }
};
