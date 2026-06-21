<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\JasaOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MerchantReportController extends Controller
{
    /**
     * Get merchant transaction reports
     *
     * Refactored: Uses orders as primary source for jasa transactions,
     * service_orders for backward compatibility
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
            // PRIMARY: Use orders table, fallback to service_orders
            $summary = $this->getJasaMerchantSummaryFromOrders($merchantId, $startDate, $endDate);
            $transactions = $this->getJasaMerchantTransactionsFromOrders($merchantId, $startDate, $endDate, $sortBy, $perPage);
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
     * Get summary for jasa merchant from orders table (primary source)
     * NOTE: Fallback to service_orders telah dihapus.
     */
    private function getJasaMerchantSummaryFromOrders(int $merchantId, Carbon $startDate, Carbon $endDate)
    {
        // PRIMARY: Query from orders table
        // Use whereHas('jasaItems') to detect jasa orders (NOT order_type='jasa')
        $baseQuery = Order::where('merchant_id', $merchantId)
            ->whereHas('jasaItems')
            ->whereBetween('created_at', [$startDate, $endDate]);

        // For COD: count if status = selesai
        // For non-COD: count if payment_status = PAID and status = selesai
        $completedQuery = clone $baseQuery;
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

        $totalTransaksiFromOrders = $completedQuery->count();
        $pendapatanFromOrders = (float) $completedQuery->sum('total_price');

        // Check if orders table has data
        // NOTE: Fallback to service_orders telah dihapus.
        // Semua data jasa baru harus sudah ada di orders + jasa_order_items
        if ($totalTransaksiFromOrders === 0) {
            // Tidak ada data di orders table, return kosong
            return [
                'total_transaksi' => 0,
                'pendapatan_bersih' => 0,
                'saldo_bisa_ditarik' => 0,
                'saldo_ditahan' => 0,
            ];
        }

        // Use orders table data
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
            'total_transaksi' => $totalTransaksiFromOrders,
            'pendapatan_bersih' => $pendapatanFromOrders,
            'saldo_bisa_ditarik' => $saldoBisaDitarik,
            'saldo_ditahan' => $saldoDitahan,
        ];
    }

    /**
     * Get transactions list for jasa merchant from orders table (primary source)
     */
    private function getJasaMerchantTransactionsFromOrders(int $merchantId, Carbon $startDate, Carbon $endDate, string $sortBy, int $perPage)
    {
        // PRIMARY: Query from orders table
        // Use whereHas('jasaItems') to detect jasa orders (NOT order_type='jasa')
        // NOTE: Load jasaItems WITHOUT eager-loading jasa relation to avoid live data reads.
        // Use snapshot accessors instead: $jasaItem->jasa_title, $jasaItem->jasa_image_url
        $query = Order::with(['jasaItems:id,order_id,jasa_id,jasa_title_snapshot,jasa_image_snapshot'])
            ->where('merchant_id', $merchantId)
            ->whereHas('jasaItems')
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

        // NOTE: Fallback to service_orders telah dihapus.
        // Semua data jasa baru harus sudah ada di orders + jasa_order_items
        if ($paginated->total() === 0) {
            return [
                'data' => [],
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => 1,
                    'per_page' => $paginated->perPage(),
                    'total' => 0,
                ]
            ];
        }

        $transactions = $paginated->map(function ($order) {
            $isWithdrawable = $order->updated_at->lt(Carbon::now()->subHours(24));

            // Get jasa data from SNAPSHOT (jasa_title_snapshot accessor)
            $jasaItem = $order->jasaItems->first();

            return [
                'id' => $order->id,
                'order_number' => 'SO-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                // Service name dari SNAPSHOT (jasa_title accessor: snapshot > live)
                'service_name' => $jasaItem?->jasa_title,
                // Customer name dari SNAPSHOT (customer_name accessor: snapshot > user > nama)
                'customer_name' => $order->customer_name,
                // Payment dari SNAPSHOT
                'payment_method' => $order->payment_method_display,
                'payment_channel' => $order->payment_channel_snapshot ?? $order->payment_channel ?? null,
                'total_price' => (float) $order->total_payment_display, // snapshot > total_price
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'status_label' => $this->getStatusLabel($order->status),
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

    /**
     * Get status label untuk jasa order.
     * Mapping status Order ke label yang sesuai.
     *
     * @param string|null $status
     * @return string
     */
    private function getStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Menunggu Pembayaran',
            'menunggu_konfirmasi_merchant' => 'Menunggu Konfirmasi',
            'diterima' => 'Diterima',
            'ditolak' => 'Ditolak',
            'layanan_dikerjakan' => 'Sedang Dikerjakan',
            'menunggu_konfirmasi_selesai' => 'Menunggu Konfirmasi Selesai',
            'selesai' => 'Selesai',
            'dibatalkan' => 'Dibatalkan',
            default => ucfirst($status ?? 'Unknown'),
        };
    }
}