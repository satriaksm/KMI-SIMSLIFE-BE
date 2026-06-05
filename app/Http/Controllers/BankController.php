<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BankController extends Controller
{
    public function index()
    {
        try {
            $banks = \Illuminate\Support\Facades\Cache::remember('xendit_banks', 86400, function () {
                /** @var Response $response */
                $response = Http::withBasicAuth(
                    config('services.xendit.secret_key'),
                    ''
                )->get('https://api.xendit.co/available_disbursements_banks');

                if ($response->failed()) {
                    throw new \Exception('Gagal mengambil data bank: ' . $response->status());
                }

                $payload = $response->json();
                if (!is_array($payload)) {
                    throw new \Exception('Format response bank tidak valid');
                }

                return collect($payload)->map(function (array $bank) {
                    return [
                        'code' => (string) ($bank['code'] ?? ''),
                        'name' => (string) ($bank['name'] ?? ''),
                    ];
                })->filter(fn(array $bank) => $bank['code'] !== '' && $bank['name'] !== '')
                  ->values()
                  ->all();
            });

            return ApiResponse::success($banks, 'Bank list berhasil diambil');

        } catch (\Throwable $e) {
            return ApiResponse::error('Error mengambil bank list', 500, $e->getMessage());
        }
    }
}