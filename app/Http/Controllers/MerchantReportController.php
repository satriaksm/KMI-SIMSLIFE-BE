<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\ServiceOrder;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MerchantReportController extends Controller
{
    /**
     * Get merchant transaction reports
     *
     * @param Request $request
     * @param string $merchantSlug
     * @return \Illuminate\Http\JsonResponse
     */
    public function transactions(Request $request, string $merchantSlug)
    {
        $user = $request->user();

        // Find merchant by slug
        $merchant = Merchant::where('slug', $merchantSlug)->first();

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant tidak ditemukan'
            ], 404);
        }

        // Validate ownership
        if ((int) $merchant->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke merchant ini'
            ], 403);
        }

        $merchantId = $merchant->id;
        $isJasaMerchant = (int) $merchant->segmentation_id === 3;

        // Parse filters
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : Carbon::now()->endOfDay();

        $sortBy = $request->input('sort_by', 'newest');
        $perPage = min((int) $request->input('per_page', 10), 50);

        // Build summary data based on merchant type
        if ($isJasaMerchant) {
            $summary = $this->getJasaMerchantSummary($merchantId, $startDate, $endDate);
            $transactions = $this->getJasaMerchantTransactions($merchantId, $startDate, $endDate, $sortBy, $perPage);
        } else {
            $summary = $this->getProductMerchantSummary($merchantId, $startDate, $endDate);
            $transactions = $this->getProductMerchantTransactions($merchantId, $startDate, $endDate, $sortBy, $perPage);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data laporan berhasil diambil',
            'data' => [
                'transactions' => $transactions['data'],
                'summary' => [
                    'total_transactions' => $summary['total_transaksi'],
                    'total_revenue' => $summary['pendapatan_bersih'],
                    'withdrawable_balance' => $summary['saldo_bisa_ditarik'],
                    'pending_balance' => $summary['saldo_ditahan'],
                ],
                'wallet' => [
                    'balance_withdrawable' => $summary['saldo_bisa_ditarik'],
                    'balance_pending' => $summary['saldo_ditahan'],
                ]
            ],
            'meta' => [
                'pagination' => $transactions['pagination']
            ]
        ]);
    }

    /**
     * Get summary for jasa merchant (segmentation_id = 3)
     */
    private function getJasaMerchantSummary(int $merchantId, Carbon $startDate, Carbon $endDate)
    {
        $baseQuery = ServiceOrder::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$startDate, $endDate]);

        // Completed orders query
        $completedQuery = clone $baseQuery;

        // For COD: count if status completed
        // For non-COD: count if payment_status = PAID and status completed
        $completedQuery->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('payment_method', 'COD')
                    ->where('status', 'selesai');
            })->orWhere(function ($inner) {
                $inner->where('payment_method', '!=', 'COD')
                    ->where('status', 'selesai')
                    ->where('payment_status', 'PAID');
            });
        });

        // Total transaksi (count of completed orders)
        $totalTransaksi = $completedQuery->count();

        // Total pendapatan bersih (sum of completed orders)
        $pendapatanBersih = (float) $completedQuery->sum('total_price');

        // Completed orders for balance calculation
        $completedOrders = clone $baseQuery;
        $completedOrders->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('payment_method', 'COD')
                    ->where('status', 'selesai');
            })->orWhere(function ($inner) {
                $inner->where('payment_method', '!=', 'COD')
                    ->where('status', 'selesai')
                    ->where('payment_status', 'PAID');
            });
        });

        // Saldo bisa ditarik: completed more than 24 hours ago
        $saldoBisaDitarik = (float) $completedOrders
            ->clone()
            ->where('updated_at', '<=', Carbon::now()->subHours(24))
            ->sum('total_price');

        // Saldo ditahan: completed less than 24 hours ago
        $saldoDitahan = (float) $completedOrders
            ->clone()
            ->where('updated_at', '>', Carbon::now()->subHours(24))
            ->sum('total_price');

        return [
            'total_transaksi' => $totalTransaksi,
            'pendapatan_bersih' => $pendapatanBersih,
            'saldo_bisa_ditarik' => $saldoBisaDitarik,
            'saldo_ditahan' => $saldoDitahan,
        ];
    }

    /**
     * Get transactions list for jasa merchant
     */
    private function getJasaMerchantTransactions(int $merchantId, Carbon $startDate, Carbon $endDate, string $sortBy, int $perPage)
    {
        $query = ServiceOrder::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('payment_method', 'COD')
                        ->where('status', 'selesai');
                })->orWhere(function ($inner) {
                    $inner->where('payment_method', '!=', 'COD')
                        ->where('status', 'selesai')
                        ->where('payment_status', 'PAID');
                });
            });

        // Sort
        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $paginated = $query->paginate($perPage);

        $transactions = $paginated->map(function ($order) {
            $isWithdrawable = $order->updated_at->lt(Carbon::now()->subHours(24));

            return [
                'id' => $order->id,
                'order_number' => $order->order_number ?? 'SO-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'service_name' => $order->service_name,
                'customer_name' => $order->customer_name,
                'total_price' => (float) $order->total_price,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'status_label' => $order->status_label,
                'created_at' => $order->created_at->toIso8601String(),
                'updated_at' => $order->updated_at->toIso8601String(),
                'withdrawable' => $isWithdrawable,
            ];
        });

        return [
            'data' => $transactions,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        ];
    }

    /**
     * Get summary for product/kuliner merchant
     */
    private function getProductMerchantSummary(int $merchantId, Carbon $startDate, Carbon $endDate)
    {
        $baseQuery = Order::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$startDate, $endDate]);

        // Completed orders: status = 'completed' or 'selesai'
        // For non-COD: payment_status = 'PAID'
        $completedQuery = clone $baseQuery;
        $completedQuery->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('payment_method', 'COD')
                    ->whereIn('status', ['completed', 'selesai']);
            })->orWhere(function ($inner) {
                $inner->where('payment_method', '!=', 'COD')
                    ->whereIn('status', ['completed', 'selesai'])
                    ->where('payment_status', 'PAID');
            });
        });

        $totalTransaksi = $completedQuery->count();
        $pendapatanBersih = (float) $completedQuery->sum('total_price');

        // Saldo bisa ditarik & ditahan
        $completedOrders = clone $baseQuery;
        $completedOrders->where(function ($q) {
            $q->where(function ($inner) {
                $inner->where('payment_method', 'COD')
                    ->whereIn('status', ['completed', 'selesai']);
            })->orWhere(function ($inner) {
                $inner->where('payment_method', '!=', 'COD')
                    ->whereIn('status', ['completed', 'selesai'])
                    ->where('payment_status', 'PAID');
            });
        });

        $saldoBisaDitarik = (float) $completedOrders
            ->clone()
            ->where('updated_at', '<=', Carbon::now()->subHours(24))
            ->sum('total_price');

        $saldoDitahan = (float) $completedOrders
            ->clone()
            ->where('updated_at', '>', Carbon::now()->subHours(24))
            ->sum('total_price');

        return [
            'total_transaksi' => $totalTransaksi,
            'pendapatan_bersih' => $pendapatanBersih,
            'saldo_bisa_ditarik' => $saldoBisaDitarik,
            'saldo_ditahan' => $saldoDitahan,
        ];
    }

    /**
     * Get transactions list for product merchant
     */
    private function getProductMerchantTransactions(int $merchantId, Carbon $startDate, Carbon $endDate, string $sortBy, int $perPage)
    {
        $query = Order::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('payment_method', 'COD')
                        ->whereIn('status', ['completed', 'selesai']);
                })->orWhere(function ($inner) {
                    $inner->where('payment_method', '!=', 'COD')
                        ->whereIn('status', ['completed', 'selesai'])
                        ->where('payment_status', 'PAID');
                });
            });

        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $paginated = $query->paginate($perPage);

        $transactions = $paginated->map(function ($order) {
            $isWithdrawable = $order->updated_at->lt(Carbon::now()->subHours(24));

            return [
                'id' => $order->id,
                'order_number' => $order->order_number ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'service_name' => $order->items ?? 'Pesanan',
                'customer_name' => $order->nama ?? 'Pelanggan',
                'total_price' => (float) ($order->total_price ?? $order->total ?? 0),
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'status_label' => ucfirst($order->status ?? 'Unknown'),
                'created_at' => $order->created_at->toIso8601String(),
                'updated_at' => $order->updated_at->toIso8601String(),
                'withdrawable' => $isWithdrawable,
            ];
        });

        return [
            'data' => $transactions,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        ];
    }
}