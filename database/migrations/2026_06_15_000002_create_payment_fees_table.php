<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_fees', function (Blueprint $table) {
            $table->id();
            $table->string('method_code', 50)->comment('VA, QRIS, EWALLET, SHOPEEPAY, RETAIL');
            $table->string('method_name', 100)->comment('Nama lengkap metode pembayaran');
            $table->enum('type', ['percentage', 'fixed'])->default('fixed');
            $table->decimal('value', 10, 2)->comment('Fee value: percentage (2.5) or fixed amount (4440)');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('method_code', 'payment_fees_method_code_unique');
            $table->index('is_active');
        });

        // Seed default payment fees
        $this->seedDefaultFees();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_fees');
    }

    /**
     * Seed default payment fees berdasarkan staging-ta reference.
     */
    protected function seedDefaultFees(): void
    {
        // Default fees berdasarkan konfigurasi umum Xendit
        $fees = [
            [
                'method_code' => 'VA',
                'method_name' => 'Virtual Account',
                'type' => 'fixed',
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
                'type' => 'fixed',
                'value' => 5000,
                'description' => 'Biaya admin Convenience Store (Alfamart, Indomaret)',
                'is_active' => true,
            ],
        ];

        foreach ($fees as $fee) {
            \DB::table('payment_fees')->updateOrInsert(
                ['method_code' => $fee['method_code']],
                $fee
            );
        }
    }
};
