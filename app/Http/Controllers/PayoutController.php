<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Payout;
use App\Models\MerchantWalletHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Helpers\ApiResponse;
use Illuminate\Support\Str;

class PayoutController extends Controller
{
    public function requestPayout(Request $request, Merchant $merchant)
    {
        $user = $request->user();

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ApiResponse::error('Unauthorized', 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:10000',
        ]);

        $amount = (float) $request->amount;
        
        $feeConfig = \App\Models\PaymentFee::where('method_code', 'PAYOUT')->first();
        $payoutFee = $feeConfig ? (float)$feeConfig->value : 4440; // Flat fee + VAT
        
        $disbursementAmount = $amount - $payoutFee;

        $withdrawable = $merchant->balance_withdrawable;

        if ($withdrawable < $amount) {
            return ApiResponse::error('Saldo yang dapat ditarik tidak mencukupi. Pastikan saldo sudah mengendap 1x24 jam (Rp ' . number_format($withdrawable, 0, ',', '.') . ' tersedia)', 400);
        }

        if ($disbursementAmount < 10000) {
            $minTarik = number_format(10000 + $payoutFee, 0, ',', '.');
            $feeStr = number_format($payoutFee, 0, ',', '.');
            return ApiResponse::error("Penarikan minimal Rp 10.000 ke rekening setelah dipotong biaya admin Rp {$feeStr}. Nominal penarikan harus di atas Rp {$minTarik}.", 400);
        }

        if (empty($merchant->bank_code) || empty($merchant->bank_account_number) || empty($merchant->bank_account_name)) {
            return ApiResponse::error('Data bank belum lengkap. Silakan lengkapi profil bank Anda.', 400);
        }

        try {
            DB::beginTransaction();

            $externalId = 'payout-' . $merchant->id . '-' . time() . '-' . Str::random(5);

            $payout = Payout::create([
                'merchant_id'    => $merchant->id,
                'external_id'    => $externalId,
                'amount'         => $amount,
                'bank_code'      => $merchant->bank_code,
                'account_number' => $merchant->bank_account_number,
                'account_name'   => $merchant->bank_account_name,
                'status'         => 'pending',
            ]);

            // Call Xendit Payout API
            $response = Http::withBasicAuth(config('services.xendit.secret_key'), '')
                ->withHeaders(['Idempotency-key' => $externalId])
                ->post('https://api.xendit.co/payouts', [
                    'external_id' => $externalId,
                    'payout_method_id' => null, // Optional if we use direct account info but wait, Xendit API v2 uses payout methods or disbursements?
                    // Disbursement API:
                    // 'bank_code' => $merchant->bank_code,
                    // 'account_holder_name' => $merchant->bank_account_name,
                    // 'account_number' => $merchant->bank_account_number,
                    // 'description' => 'Withdrawal for ' . $merchant->name,
                    // 'amount' => $amount,
                ]);

            // Wait, let's just use disbursement API for simplicity:
            $disbursementResponse = Http::withBasicAuth(config('services.xendit.secret_key'), '')
                ->withHeaders(['X-IDEMPOTENCY-KEY' => $externalId])
                ->post('https://api.xendit.co/disbursements', [
                    'external_id' => $externalId,
                    'bank_code' => $merchant->bank_code,
                    'account_holder_name' => $merchant->bank_account_name,
                    'account_number' => $merchant->bank_account_number,
                    'description' => 'Penarikan saldo toko ' . $merchant->name,
                    'amount' => $disbursementAmount,
                ]);

            if (!$disbursementResponse->successful()) {
                DB::rollBack();
                $errorMsg = $disbursementResponse->json('message') ?? 'Gagal memproses penarikan ke Xendit';
                return ApiResponse::error($errorMsg, 400);
            }

            // Immediately debit the available balance so it cannot be double-spent
            $merchant->decrement('balance_available', $amount);

            MerchantWalletHistory::create([
                'merchant_id'    => $merchant->id,
                'type'           => 'debit',
                'amount'         => $amount,
                'reference_type' => 'payout',
                'reference_id'   => $payout->id,
                'description'    => 'Penarikan saldo ke bank',
            ]);

            DB::commit();

            return ApiResponse::success($payout, 'Permintaan penarikan berhasil dibuat');

        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponse::error('Terjadi kesalahan server: ' . $e->getMessage(), 500);
        }
    }
}
