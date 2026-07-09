<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PaymentFee Model
 *
 * Konfigurasi fee untuk setiap metode pembayaran.
 * Digunakan untuk menghitung platform fee saat checkout.
 *
 * Method codes:
 * - VA: Virtual Account (BCA, BNI, BRI, Mandiri, dll)
 * - QRIS: QRIS
 * - EWALLET: OVO, DANA, LINKAJA
 * - SHOPEEPAY: ShopeePay
 * - RETAIL: Alfamart, Indomaret
 */
class PaymentFee extends Model
{
    use HasFactory;

    protected $table = 'payment_fees';

    protected $fillable = [
        'method_code',
        'method_name',
        'type',
        'value',
        'description',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get display format dari fee.
     * Contoh: "2.5%" atau "Rp 5.000"
     */
    public function getDisplayAttribute(): string
    {
        if ($this->type === 'percentage') {
            return floatval($this->value) . '%';
        }

        return 'Rp ' . number_format((float) $this->value, 0, ',', '.');
    }

    /**
     * Calculate fee amount berdasarkan gross amount.
     */
    public function calculateFee(float $grossAmount): int
    {
        if ($this->type === 'percentage') {
            return (int) ceil($grossAmount * ((float) $this->value / 100));
        }

        return (int) $this->value;
    }

    /**
     * Scope untuk fee yang aktif saja.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk mencari fee berdasarkan method code.
     */
    public function scopeByCode($query, string $code)
    {
        return $query->where('method_code', strtoupper($code));
    }

    /**
     * Static method untuk mendapatkan fee berdasarkan payment method.
     */
    public static function getByPaymentMethod(string $paymentMethod): ?self
    {
        $feeCode = self::mapPaymentMethodToFeeCode($paymentMethod);

        return self::where('method_code', $feeCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Map payment method ke fee code.
     */
    public static function mapPaymentMethodToFeeCode(string $paymentMethod): string
    {
        $paymentMethod = strtoupper($paymentMethod);

        $vaMethods = ['BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'CIMB'];
        $ewalletMethods = ['OVO', 'DANA', 'LINKAJA'];
        $retailMethods = ['ALFAMART', 'INDOMARET'];

        if (in_array($paymentMethod, $vaMethods, true)) {
            return 'VA';
        }

        if ($paymentMethod === 'QRIS') {
            return 'QRIS';
        }

        if (in_array($paymentMethod, $ewalletMethods, true)) {
            return 'EWALLET';
        }

        if ($paymentMethod === 'SHOPEEPAY') {
            return 'SHOPEEPAY';
        }

        if (in_array($paymentMethod, $retailMethods, true)) {
            return 'RETAIL';
        }

        return 'VA';
    }

    /**
     * Calculate platform fee berdasarkan payment method dan gross amount.
     */
    public static function calculatePlatformFee(string $paymentMethod, float $grossAmount): int
    {
        $fee = self::getByPaymentMethod($paymentMethod);

        if ($fee) {
            return $fee->calculateFee($grossAmount);
        }

        return 4440;
    }
}
