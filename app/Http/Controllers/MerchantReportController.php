<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\MerchantTransactionExport;
use Carbon\Carbon;

class MerchantReportController extends Controller
{
    private function buildQuery(Merchant $merchant, Request $request)
    {
        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items', 'user']);

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
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
        $baseQuery = $this->buildQuery($merchant, $request);
        
        $sortBy = $request->input('sort_by', 'newest');
        if ($sortBy === 'oldest') {
            $baseQuery->orderBy('created_at', 'asc');
        } else {
            $baseQuery->orderBy('created_at', 'desc');
        }

        // Calculate summary
        $totalTransactions = $baseQuery->count();
        
        // Income is only calculated for completed orders
        $totalRevenueQuery = clone $baseQuery;
        // clear order by for aggregate query just in case
        $totalRevenueQuery->getQuery()->orders = null;
        $totalRevenue = $totalRevenueQuery->where('status', 'completed')->sum('net_amount');

        $perPage = (int) $request->input('per_page', 10);
        if ($perPage < 1) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        $orders = $baseQuery->paginate($perPage)->appends($request->query());

        return ApiResponse::success([
            'summary' => [
                'total_transactions' => $totalTransactions,
                'total_revenue' => $totalRevenue,
            ],
            'wallet' => [
                'balance_available'    => (float) $merchant->balance_available,
                'balance_pending'      => (float) $merchant->balance_pending,
                'balance_held'         => (float) $merchant->balance_held,
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
            })
        ], 'Laporan berhasil diambil', 200, [
            'pagination' => [
                'total'        => $orders->total(),
                'per_page'     => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'next_page_url' => $orders->nextPageUrl(),
                'prev_page_url' => $orders->previousPageUrl(),
            ],
        ]);
    }

    public function exportPdf(Request $request, Merchant $merchant)
    {
        $query = $this->buildQuery($merchant, $request);
        $sortBy = $request->input('sort_by', 'newest');
        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
        $transactions = $query->get();
        $totalRevenue = $transactions->where('status', 'completed')->sum('net_amount');

        $data = [
            'merchant' => $merchant,
            'transactions' => $transactions,
            'totalRevenue' => $totalRevenue,
            'startDate' => $request->input('start_date'),
            'endDate' => $request->input('end_date'),
            'status' => $request->input('status', 'all')
        ];

        $pdf = Pdf::loadView('exports.merchant_report', $data);
        
        return $pdf->download('Laporan_Transaksi_' . $merchant->slug . '_' . now()->format('Ymd') . '.pdf');
    }

    public function exportExcel(Request $request, Merchant $merchant)
    {
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
