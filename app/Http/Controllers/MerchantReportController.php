<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Exports\MerchantTransactionExport;
use App\Models\Merchant;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MerchantReportController extends Controller
{
    /**
     * Build search query for merchant orders.
     */
    private function buildQuery(Merchant $merchant, Request $request)
    {
        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items', 'user']);

        $isJasa = ((int) $merchant->segmentation_id === 3);

        // Segregate product/kuliner orders from jasa orders.
        if ($isJasa) {
            $query->where('order_type', 'jasa');
        } else {
            $query->where(function ($q) {
                $q->where('order_type', '!=', 'jasa')
                    ->orWhereNull('order_type');
            });
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $status = $request->input('status');

            if ($isJasa) {
                // Map frontend product statuses to jasa statuses.
                if ($status === 'completed') {
                    $query->where('status', 'selesai');
                } elseif ($status === 'responsed') {
                    $query->whereIn('status', ['diterima', 'layanan_dikerjakan']);
                } elseif ($status === 'delivered') {
                    $query->where('status', 'menunggu_konfirmasi_selesai');
                } elseif ($status === 'cancelled') {
                    $query->whereIn('status', ['dibatalkan', 'ditolak', 'expired']);
                } elseif ($status === 'paid') {
                    $query->where('status', 'menunggu_konfirmasi');
                } elseif ($status === 'pending') {
                    $query->where('status', 'pending');
                } else {
                    $query->where('status', $status);
                }
            } else {
                $query->where('status', $status);
            }
        } else {
            $query->where(function ($qBuilder) {
                $qBuilder->where('status', '!=', 'pending')
                    ->orWhere('payment_method', 'COD');
            });
        }

        return $query;
    }

    public function index(Request $request, Merchant $merchant)
    {
        $user = $request->user();

        if ((int) $merchant->user_id !== (int) $user->id) {
            return ApiResponse::error('Anda tidak memiliki akses ke merchant ini', 403);
        }

        $isJasaMerchant = ((int) $merchant->segmentation_id === 3);

        $sortBy = $request->input('sort_by', 'newest');

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        if ($isJasaMerchant) {
            $summary = $this->getJasaMerchantSummaryFromOrders($merchant, $request);
            $transactions = $this->getJasaMerchantTransactionsFromOrders($merchant, $request, $sortBy, $perPage);

            return ApiResponse::success([
                'summary' => [
                    'total_transactions' => $summary['total_transaksi'],
                    'total_revenue' => $summary['pendapatan_bersih'],
                    'withdrawable_balance' => $summary['saldo_bisa_ditarik'],
                    'pending_balance' => $summary['saldo_ditahan'],
                ],
                'wallet' => [
                    'balance_available' => (float) $merchant->balance_available,
                    'balance_pending' => $summary['saldo_ditahan'],
                    'balance_held' => (float) $merchant->balance_held,
                    'balance_withdrawable' => $summary['saldo_bisa_ditarik'],
                ],
                'transactions' => $transactions['data'],
            ], 'Laporan berhasil diambil', 200, [
                'pagination' => $transactions['pagination'],
            ]);
        }

        $baseQuery = $this->buildQuery($merchant, $request);

        if ($sortBy === 'oldest') {
            $baseQuery->orderBy('created_at', 'asc');
        } else {
            $baseQuery->orderBy('created_at', 'desc');
        }

        $totalTransactions = (clone $baseQuery)->count();

        $totalRevenueQuery = clone $baseQuery;
        $totalRevenueQuery->getQuery()->orders = null;
        $totalRevenue = $totalRevenueQuery
            ->where('status', 'completed')
            ->sum('net_amount');

        $orders = $baseQuery->paginate($perPage)->appends($request->query());

        return ApiResponse::success([
            'summary' => [
                'total_transactions' => $totalTransactions,
                'total_revenue' => $totalRevenue,
            ],
            'wallet' => [
                'balance_available' => (float) $merchant->balance_available,
                'balance_pending' => (float) $merchant->balance_pending,
                'balance_held' => (float) $merchant->balance_held,
                'balance_withdrawable' => (float) $merchant->balance_withdrawable,
            ],
            'transactions' => collect($orders->items())->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_code' => $order->order_code,
                    'created_at' => $order->created_at,
                    'status' => $order->status,
                    'customer_name' => $order->user_name_snapshot,
                    'gross_amount' => $order->gross_amount,
                    'platform_fee' => $order->platform_fee,
                    'net_amount' => $order->net_amount,
                    'payment_method' => $order->payment_method,
                    'delivery_type' => $order->delivery_type,
                ];
            }),
        ], 'Laporan berhasil diambil', 200, [
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'next_page_url' => $orders->nextPageUrl(),
                'prev_page_url' => $orders->previousPageUrl(),
            ],
        ]);
    }

    /**
     * transactions endpoint alias for jasa/product merchant report.
     */
    public function transactions(Request $request, Merchant $merchant)
    {
        return $this->index($request, $merchant);
    }

    /**
     * Get summary for jasa merchant from orders table.
     */
    private function getJasaMerchantSummaryFromOrders(Merchant $merchant, Request $request): array
    {
        $baseQuery = $this->buildQuery($merchant, $request);

        $completedQuery = clone $baseQuery;
        $completedQuery->getQuery()->orders = null;
        $completedQuery->where('status', 'selesai');

        $totalTransaksi = (clone $baseQuery)->count();

        // Merchant dashboard displays revenue from service subtotal, excluding customer fees.
        $pendapatanBersih = (float) (clone $completedQuery)->sum('subtotal_snapshot');

        $withdrawableQuery = clone $completedQuery;
        $saldoBisaDitarik = (float) $withdrawableQuery
            ->where('updated_at', '<=', Carbon::now()->subHours(24))
            ->sum('subtotal_snapshot');

        $pendingQuery = clone $completedQuery;
        $saldoDitahan = (float) $pendingQuery
            ->where('updated_at', '>', Carbon::now()->subHours(24))
            ->sum('subtotal_snapshot');

        return [
            'total_transaksi' => $totalTransaksi,
            'pendapatan_bersih' => $pendapatanBersih,
            'saldo_bisa_ditarik' => $saldoBisaDitarik,
            'saldo_ditahan' => $saldoDitahan,
        ];
    }

    /**
     * Get transactions list for jasa merchant from orders table.
     */
    private function getJasaMerchantTransactionsFromOrders(Merchant $merchant, Request $request, string $sortBy, int $perPage): array
    {
        $query = $this->buildQuery($merchant, $request);
        $query->with(['jasaItems:id,order_id,jasa_id,jasa_title_snapshot,jasa_image_snapshot']);

        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $paginated = $query->paginate($perPage)->appends($request->query());

        if ($paginated->total() === 0) {
            return [
                'data' => [],
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => 1,
                    'per_page' => $paginated->perPage(),
                    'total' => 0,
                ],
            ];
        }

        $paymentMethodLabels = [
            'cod' => 'Bayar di Tempat (COD)',
            'COD' => 'Bayar di Tempat (COD)',
            'ONLINE_XENDIT' => 'Online (Xendit)',
            'QRIS' => 'QRIS',
            'BCA_VA' => 'BCA Virtual Account',
            'BNI_VA' => 'BNI Virtual Account',
            'BRI_VA' => 'BRI Virtual Account',
            'MANDIRI_VA' => 'Mandiri Virtual Account',
            'OVO' => 'OVO',
            'DANA' => 'DANA',
            'SHOPEEPAY' => 'ShopeePay',
            'ALFAMART' => 'Alfamart / Alfamidi',
        ];

        $transactions = $paginated->map(function ($order) use ($paymentMethodLabels) {
            $isWithdrawable = $order->status === 'selesai'
                && $order->updated_at
                && $order->updated_at->lt(Carbon::now()->subHours(24));

            $jasaItem = $order->jasaItems->first();

            $paymentMethod = $order->payment_method_snapshot ?? $order->payment_method ?? 'COD';
            $paymentMethodDisplay = $paymentMethodLabels[strtoupper($paymentMethod)]
                ?? $paymentMethodLabels[strtolower($paymentMethod)]
                ?? $paymentMethod;

            return [
                'id' => $order->id,
                'order_number' => $order->order_code ?? ('ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT)),
                'order_code' => $order->order_code ?? ('ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT)),
                'order_type' => 'jasa',
                'service_name' => $jasaItem?->jasa_title_snapshot ?? $jasaItem?->jasa_title ?? 'Layanan',
                'customer_name' => $order->customer_name_snapshot
                    ?? $order->user_name_snapshot
                    ?? $order->nama
                    ?? 'Pelanggan',
                'payment_method' => $paymentMethodDisplay,
                'payment_channel' => $order->payment_channel_snapshot ?? $order->payment_channel ?? null,
                'total_price' => (float) ($order->subtotal_snapshot ?? $order->total_price ?? 0),
                'gross_amount' => (float) ($order->total_payment_snapshot ?? $order->total_price ?? 0),
                'platform_fee' => (float) ($order->platform_fee_snapshot ?? $order->platform_fee ?? 0),
                'net_amount' => (float) (
                    $order->subtotal_snapshot
                    ?? (($order->total_price ?? 0) - ($order->platform_fee ?? 0))
                ),
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'status_label' => $this->getStatusLabel($order->status),
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
                'withdrawable' => $isWithdrawable,
                'delivery_type' => 'jasa',
            ];
        });

        return [
            'data' => $transactions,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * Get status label untuk jasa order.
     */
    private function getStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Menunggu Pembayaran',
            'menunggu_konfirmasi_merchant' => 'Menunggu Konfirmasi',
            'menunggu_konfirmasi' => 'Menunggu Konfirmasi',
            'diterima' => 'Diterima',
            'ditolak' => 'Ditolak',
            'layanan_dikerjakan' => 'Sedang Dikerjakan',
            'menunggu_konfirmasi_selesai' => 'Menunggu Konfirmasi Selesai',
            'selesai' => 'Selesai',
            'dibatalkan' => 'Dibatalkan',
            'expired' => 'Kadaluarsa',
            default => ucfirst($status ?? 'Unknown'),
        };
    }

    public function exportPdf(Request $request, Merchant $merchant)
    {
        $user = $request->user();

        if ((int) $merchant->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = $this->buildQuery($merchant, $request);
        $sortBy = $request->input('sort_by', 'newest');

        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $transactions = $query->get();

        $isJasaMerchant = ((int) $merchant->segmentation_id === 3);

        if ($isJasaMerchant) {
            // For jasa, calculate sum of subtotal_snapshot for completed/selesai orders.
            $totalRevenue = $transactions->where('status', 'selesai')->sum('subtotal_snapshot');
        } else {
            // For product, sum of net_amount for completed orders.
            $totalRevenue = $transactions->where('status', 'completed')->sum('net_amount');
        }

        $data = [
            'merchant' => $merchant,
            'transactions' => $transactions,
            'totalRevenue' => $totalRevenue,
            'startDate' => $request->input('start_date'),
            'endDate' => $request->input('end_date'),
            'status' => $request->input('status', 'all'),
            'isJasaMerchant' => $isJasaMerchant,
        ];

        $pdf = Pdf::loadView('exports.merchant_report', $data);

        return $pdf->download('Laporan_Transaksi_' . $merchant->slug . '_' . now()->format('Ymd') . '.pdf');
    }

    public function exportExcel(Request $request, Merchant $merchant)
    {
        $user = $request->user();

        if ((int) $merchant->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = $this->buildQuery($merchant, $request);
        $sortBy = $request->input('sort_by', 'newest');

        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $transactions = $query->get();

        return Excel::download(
            new MerchantTransactionExport($transactions),
            'Laporan_Transaksi_' . $merchant->slug . '_' . now()->format('Ymd') . '.xlsx'
        );
    }
}
