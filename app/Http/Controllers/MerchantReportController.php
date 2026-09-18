<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantReportController extends Controller
{
    /**
     * Get transaction reports with summary & pagination
     */
    public function transactions(Request $request, Merchant $merchant)
    {
        $this->checkMerchantAccess($request, $merchant);

        $completedStatuses = ['completed', 'selesai'];
        $cancelledStatuses = ['cancelled', 'rejected', 'undelivered', 'unpicked', 'batal', 'gagal'];
        $allowedStatuses = array_merge($completedStatuses, $cancelledStatuses);

        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items.product', 'user']);

        // Filter: Status (Hanya Selesai dan Gagal/Batal, transaksi menunggu tidak muncul)
        $status = $request->query('status');
        if ($status === 'completed') {
            $query->whereIn('status', $completedStatuses);
        } elseif ($status === 'cancelled' || $status === 'gagal' || $status === 'batal') {
            $query->whereIn('status', $cancelledStatuses);
        } else {
            $query->whereIn('status', $allowedStatuses);
        }

        // Filter: Date range
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->query('end_date'));
        }

        // Filter: Search query (q / search)
        $search = $request->query('q', $request->query('search'));
        if (!empty($search)) {
            $q = trim((string) $search);
            $searchClosure = function ($sub) use ($q) {
                $sub->where('order_code', 'like', "%{$q}%")
                    ->orWhere('nama', 'like', "%{$q}%")
                    ->orWhere('tel', 'like', "%{$q}%")
                    ->orWhereHas('items.product', function ($pq) use ($q) {
                        $pq->where('name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('jasa', function ($jq) use ($q) {
                        $jq->where('title', 'like', "%{$q}%");
                    })
                    ->orWhereHas('user', function ($uq) use ($q) {
                        $uq->where('name', 'like', "%{$q}%")
                           ->orWhere('phone', 'like', "%{$q}%");
                    });
            };
            $query->where($searchClosure);
        }

        // Summary Calculations (hanya dari transaksi selesai & gagal/batal)
        $summaryQuery = Order::query()
            ->where('merchant_id', $merchant->id);

        if (!empty($search)) {
            $summaryQuery->where($searchClosure);
        }

        if ($status === 'completed') {
            $summaryQuery->whereIn('status', $completedStatuses);
        } elseif ($status === 'cancelled' || $status === 'gagal' || $status === 'batal') {
            $summaryQuery->whereIn('status', $cancelledStatuses);
        } else {
            $summaryQuery->whereIn('status', $allowedStatuses);
        }

        if ($request->filled('start_date')) {
            $summaryQuery->whereDate('created_at', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $summaryQuery->whereDate('created_at', '<=', $request->query('end_date'));
        }

        $completedQuery = (clone $summaryQuery)->whereIn('status', $completedStatuses);
        $totalRevenue = (float) $completedQuery->sum('total');
        $totalTransactions = $summaryQuery->count();

        // Sort
        if ($request->query('sort_by') === 'oldest') {
            $query->oldest();
        } else {
            $query->latest();
        }

        // Pagination
        $perPage = (int) $request->query('per_page', 10);
        $orders = $query->paginate($perPage);

        $formattedTransactions = collect($orders->items())->map(function ($order) {
            $isCancelled = in_array($order->status, ['cancelled', 'batal', 'rejected', 'gagal', 'undelivered', 'unpicked'], true);
            $itemCount = $order->items->sum('quantity');
            $firstItemName = $order->items->first()?->product?->name ?? ($order->order_type === 'jasa' ? 'Layanan Jasa' : 'Item');
            return [
                'id' => $order->id,
                'order_code' => $order->order_code ?: ('ORD-' . $order->id),
                'order_type' => $order->order_type ?: ($order->jasa_id ? 'jasa' : 'product'),
                'created_at' => $order->created_at?->toIso8601String(),
                'customer_name' => $order->nama ?: ($order->user?->name ?? 'Pelanggan'),
                'customer_phone' => $order->tel ?: ($order->user?->phone ?? '-'),
                'payment_method' => $order->payment_method === 'WhatsApp' ? 'Belum Ditetapkan' : ($order->payment_method ?: ($order->metode_pembayaran ?: 'Belum Ditetapkan')),
                'delivery_type' => $isCancelled ? '-' : ($order->delivery_type === 'delivery' ? 'Kirim' : ($order->delivery_type === 'pickup' ? 'Ambil Sendiri' : ($order->delivery_type ?: '-'))),
                'status' => in_array($order->status, ['selesai'], true) ? 'completed' : (in_array($order->status, ['batal'], true) ? 'cancelled' : $order->status),
                'item_count' => $itemCount > 0 ? $itemCount : $order->items->count(),
                'first_item_name' => $firstItemName,
                'subtotal' => (float) ($order->subtotal ?: $order->total),
                'discount_total' => (float) ($order->discount_total ?: 0),
                'shipping_fee' => (float) ($order->shipping_fee ?: 0),
                'gross_amount' => (float) $order->total,
                'platform_fee' => 0,
                'net_amount' => $isCancelled ? 0 : (float) $order->total,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $formattedTransactions,
                'summary' => [
                    'total_transactions' => $totalTransactions,
                    'total_revenue' => $totalRevenue,
                ],
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ],
        ]);
    }

    /**
     * Export transaction reports to PDF
     */
    public function exportPdf(Request $request, Merchant $merchant)
    {
        $this->checkMerchantAccess($request, $merchant);

        $completedStatuses = ['completed', 'selesai'];
        $cancelledStatuses = ['cancelled', 'rejected', 'undelivered', 'unpicked', 'batal', 'gagal'];
        $allowedStatuses = array_merge($completedStatuses, $cancelledStatuses);

        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items.product', 'user']);

        // Apply filters
        $status = $request->query('status');
        if ($status === 'completed') {
            $query->whereIn('status', $completedStatuses);
        } elseif ($status === 'cancelled' || $status === 'gagal' || $status === 'batal') {
            $query->whereIn('status', $cancelledStatuses);
        } else {
            $query->whereIn('status', $allowedStatuses);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->query('end_date'));
        }

        $search = $request->query('q', $request->query('search'));
        if (!empty($search)) {
            $q = trim((string) $search);
            $query->where(function ($sub) use ($q) {
                $sub->where('order_code', 'like', "%{$q}%")
                    ->orWhere('nama', 'like', "%{$q}%")
                    ->orWhere('tel', 'like', "%{$q}%")
                    ->orWhereHas('items.product', function ($pq) use ($q) {
                        $pq->where('name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('jasa', function ($jq) use ($q) {
                        $jq->where('title', 'like', "%{$q}%");
                    })
                    ->orWhereHas('user', function ($uq) use ($q) {
                        $uq->where('name', 'like', "%{$q}%")
                           ->orWhere('phone', 'like', "%{$q}%");
                    });
            });
        }

        if ($request->query('sort_by') === 'oldest') {
            $query->oldest();
        } else {
            $query->latest();
        }

        $orders = $query->get();

        $completedOrders = $orders->filter(fn ($o) => in_array($o->status, ['completed', 'selesai'], true));
        $totalRevenue = (float) $completedOrders->sum('total');

        $pdf = Pdf::loadView('exports.merchant_transactions', [
            'merchant' => $merchant,
            'orders' => $orders,
            'totalRevenue' => $totalRevenue,
            'totalTransactions' => $orders->count(),
            'startDate' => $request->query('start_date'),
            'endDate' => $request->query('end_date'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download("Laporan_Transaksi_{$merchant->slug}_" . now()->format('YmdHis') . '.pdf');
    }

    /**
     * Export transaction reports to Excel / CSV
     */
    public function exportExcel(Request $request, Merchant $merchant)
    {
        $this->checkMerchantAccess($request, $merchant);

        $completedStatuses = ['completed', 'selesai'];
        $cancelledStatuses = ['cancelled', 'rejected', 'undelivered', 'unpicked', 'batal', 'gagal'];
        $allowedStatuses = array_merge($completedStatuses, $cancelledStatuses);

        $query = Order::query()
            ->where('merchant_id', $merchant->id)
            ->with(['items.product', 'user']);

        $status = $request->query('status');
        if ($status === 'completed') {
            $query->whereIn('status', $completedStatuses);
        } elseif ($status === 'cancelled' || $status === 'gagal' || $status === 'batal') {
            $query->whereIn('status', $cancelledStatuses);
        } else {
            $query->whereIn('status', $allowedStatuses);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->query('end_date'));
        }

        $search = $request->query('q', $request->query('search'));
        if (!empty($search)) {
            $q = trim((string) $search);
            $query->where(function ($sub) use ($q) {
                $sub->where('order_code', 'like', "%{$q}%")
                    ->orWhere('nama', 'like', "%{$q}%")
                    ->orWhere('tel', 'like', "%{$q}%")
                    ->orWhereHas('items.product', function ($pq) use ($q) {
                        $pq->where('name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('jasa', function ($jq) use ($q) {
                        $jq->where('title', 'like', "%{$q}%");
                    })
                    ->orWhereHas('user', function ($uq) use ($q) {
                        $uq->where('name', 'like', "%{$q}%")
                           ->orWhere('phone', 'like', "%{$q}%");
                    });
            });
        }

        if ($request->query('sort_by') === 'oldest') {
            $query->oldest();
        } else {
            $query->latest();
        }

        $orders = $query->get();

        $fileName = "Laporan_Transaksi_{$merchant->slug}_" . now()->format('YmdHis') . '.csv';

        $headers = [
            'Content-type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($orders) {
            $handle = fopen('php://output', 'w');
            // Add UTF-8 BOM
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'No',
                'ID Pesanan',
                'Tanggal',
                'Nama Pembeli',
                'No. Telepon',
                'Metode Pengiriman',
                'Metode Pembayaran',
                'Status',
                'Total Nominal (Rp)',
            ]);

            $no = 1;
            foreach ($orders as $o) {
                fputcsv($handle, [
                    $no++,
                    $o->order_code ?: ('ORD-' . $o->id),
                    $o->created_at?->format('Y-m-d H:i:s') ?? '-',
                    $o->nama ?: ($o->user?->name ?? '-'),
                    $o->tel ?? '-',
                    in_array($o->status, ['cancelled', 'batal', 'rejected', 'gagal', 'undelivered', 'unpicked'], true) ? '-' : ($o->delivery_type === 'delivery' ? 'Kirim' : 'Ambil Sendiri'),
                    $o->payment_method ?: 'COD',
                    $o->status,
                    (float) $o->total,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    private function checkMerchantAccess(Request $request, Merchant $merchant): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Silakan login terlebih dahulu');
        }

        if ((int) $merchant->user_id !== (int) $user->id && !$user->hasRole('admin')) {
            abort(403, 'Akses ditolak. Anda bukan pemilik toko ini.');
        }
    }
}
