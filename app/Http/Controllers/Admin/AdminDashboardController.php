<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Order;
use App\Models\Paguyuban;
use App\Models\ContentReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class AdminDashboardController extends Controller
{
    /**
     * Get dashboard statistics
     *
     * Returns dashboard statistics including overview, products, merchants, recent orders, and recent reports.
     *
     * @authenticated
     *
     * @queryParam period string Period for statistics (all_time, last_30_days). Example: last_30_days
     *
     * @response 200 {
     *   "overview": { ... },
     *   "products": { ... },
     *   "merchants": { ... },
     *   "recent_orders": [ ... ],
     *   "recent_reports": [ ... ]
     * }
     */
    /**
     * Get dashboard statistics 
     */
    public function statistics(Request $request)
    {
        try {
            $period = $request->input('period', 'all_time');
            $cacheKey = "admin_dashboard_stats_v2_{$period}";

            // Cache seluruh statistik dashboard selama 30 detik
            $stats = Cache::remember($cacheKey, 30, function () use ($period) {
                return [
                    'overview' => $this->getOverviewStatsCached($period),
                    'products' => [
                        'total' => Cache::remember("dashboard_products_total", 30, fn() => Product::where('status', 'published')->count()),
                        'by_category' => $this->getProductsByCategoryCached(),
                    ],
                    'merchants' => [
                        'total' => Cache::remember("dashboard_merchants_total", 30, fn() => Merchant::where('status', 'approved')->count()),
                        'by_segmentation' => $this->getMerchantsBySegmentationCached(),
                    ],
                    'recent_orders' => $this->getRecentOrdersCached(),
                    'recent_reports' => $this->getRecentReportsCached(),
                ];
            });

            return response()->json($stats);
        } catch (\Exception $e) {
            Log::error('[AdminDashboard] Statistics failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to load statistics',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // --- CACHED VERSIONS ---

    private function getOverviewStatsCached(string $period): array
    {
        $cacheKey = "dashboard_overview_{$period}";
        return Cache::remember($cacheKey, 30, fn() => $this->getOverviewStats($period));
    }

    private function getProductsByCategoryCached(): array
    {
        return Cache::remember('dashboard_products_by_category', 30, fn() => $this->getProductsByCategory());
    }

    private function getMerchantsBySegmentationCached(): array
    {
        return Cache::remember('dashboard_merchants_by_segmentation', 30, fn() => $this->getMerchantsBySegmentation());
    }

    private function getRecentOrdersCached(): array
    {
        return Cache::remember('dashboard_recent_orders', 30, fn() => $this->getRecentOrders());
    }

    private function getRecentReportsCached(): array
    {
        return Cache::remember('dashboard_recent_reports', 30, fn() => $this->getRecentReports());
    }

    /**
     * Get overview statistics 
     */
    private function getOverviewStats(string $period): array
    {
        if ($period === 'last_30_days') {
            $startDate = Carbon::now()->subDays(30);
            $previousStartDate = Carbon::now()->subDays(60);
            $previousEndDate = Carbon::now()->subDays(31);

            $stats = [
                'users' => [
                    'current' => $this->safeCount(User::whereNotNull('email_verified_at')->where('created_at', '>=', $startDate)),
                    'previous' => $this->safeCount(User::whereNotNull('email_verified_at')->whereBetween('created_at', [$previousStartDate, $previousEndDate])),
                ],
                'paguyubans' => [
                    'current' => $this->safeCount(Paguyuban::where('is_active', true)->where('created_at', '>=', $startDate)),
                    'previous' => $this->safeCount(Paguyuban::where('is_active', true)->whereBetween('created_at', [$previousStartDate, $previousEndDate])),
                ],
                'products' => [
                    'current' => $this->safeCount(Product::where('status', 'published')->where('created_at', '>=', $startDate)),
                    'previous' => $this->safeCount(Product::where('status', 'published')->whereBetween('created_at', [$previousStartDate, $previousEndDate])),
                ],
                'pending_reports' => [
                    'current' => $this->safeCount(ContentReport::where('status', 'pending')->where('created_at', '>=', $startDate)),
                    'previous' => $this->safeCount(ContentReport::where('status', 'pending')->whereBetween('created_at', [$previousStartDate, $previousEndDate])),
                ],
            ];

            // Calculate growth
            foreach ($stats as $key => &$stat) {
                $stat['growth'] = $stat['previous'] > 0
                    ? round((($stat['current'] - $stat['previous']) / $stat['previous']) * 100, 2)
                    : null;
            }

            return $stats;
        }

        // All time
        return [
            'users' => [
                'current' => $this->safeCount(User::whereNotNull('email_verified_at')),
                'previous' => null,
                'growth' => null
            ],
            'paguyubans' => [
                'current' => $this->safeCount(Paguyuban::where('is_active', true)),
                'previous' => null,
                'growth' => null
            ],
            'products' => [
                'current' => $this->safeCount(Product::where('status', 'published')),
                'previous' => null,
                'growth' => null
            ],
            'pending_reports' => [
                'current' => $this->safeCount(ContentReport::where('status', 'pending')),
                'previous' => null,
                'growth' => null
            ],
        ];
    }

    /**
     * Count helper
     */
    private function safeCount($query)
    {
        try {
            return $query->count();
        } catch (\Exception $e) {
            Log::warning('[AdminDashboard] Count query failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get merchants by segmentation 
     */
    private function getMerchantsBySegmentation(): array
    {
        try {
            $result = DB::table('merchants')
                ->join('segmentations', 'merchants.segmentation_id', '=', 'segmentations.id')
                ->where('merchants.status', 'approved')
                ->select(
                    'segmentations.id',
                    'segmentations.name',
                    DB::raw('COUNT(merchants.id) as count')
                )
                ->groupBy('segmentations.id', 'segmentations.name')
                ->orderBy('segmentations.name')
                ->get()
                ->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'count' => (int) $item->count,
                ])
                ->toArray();

            return $result;
        } catch (\Exception $e) {
            Log::error('[AdminDashboard] getMerchantsBySegmentation failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get products by category 
     */
    private function getProductsByCategory(): array
    {
        try {

            // Get product morph class
            $productMorphClass = (new \App\Models\Product())->getMorphClass();

            $directParentProducts = DB::table('categories as parent')
                ->select(
                    'parent.id',
                    'parent.name',
                    DB::raw('COUNT(DISTINCT cat.categorizable_id) as count')
                )
                ->join('categorizables as cat', function ($join) use ($productMorphClass) {
                    $join->on('parent.id', '=', 'cat.category_id')
                        ->where('cat.categorizable_type', '=', $productMorphClass);
                })
                ->join('products as p', function ($join) {
                    $join->on('cat.categorizable_id', '=', 'p.id')
                        ->where('p.status', '=', 'published');
                })
                ->whereNull('parent.parent_id')
                ->groupBy('parent.id', 'parent.name')
                ->get();

            $childProducts = DB::table('categories as parent')
                ->select(
                    'parent.id',
                    'parent.name',
                    DB::raw('COUNT(DISTINCT cat.categorizable_id) as count')
                )
                ->join('categories as children', 'parent.id', '=', 'children.parent_id')
                ->join('categorizables as cat', function ($join) use ($productMorphClass) {
                    $join->on('children.id', '=', 'cat.category_id')
                        ->where('cat.categorizable_type', '=', $productMorphClass);
                })
                ->join('products as p', function ($join) {
                    $join->on('cat.categorizable_id', '=', 'p.id')
                        ->where('p.status', '=', 'published');
                })
                ->whereNull('parent.parent_id')
                ->groupBy('parent.id', 'parent.name')
                ->get();

            $categoryCounts = [];

            foreach ($directParentProducts as $item) {
                $categoryCounts[$item->id] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'count' => (int) $item->count,
                ];
            }

            foreach ($childProducts as $item) {
                if (isset($categoryCounts[$item->id])) {
                    // Add to existing count
                    $categoryCounts[$item->id]['count'] += (int) $item->count;
                } else {
                    // New entry
                    $categoryCounts[$item->id] = [
                        'id' => $item->id,
                        'name' => $item->name,
                        'count' => (int) $item->count,
                    ];
                }
            }

            if (empty($categoryCounts)) {
                Log::warning('[AdminDashboard] No products with categories found');
                return [];
            }

            usort($categoryCounts, fn($a, $b) => $b['count'] - $a['count']);

            $result = [];
            $lainnyaCount = 0;

            foreach ($categoryCounts as $index => $item) {
                if ($index < 5) {
                    $result[] = $item;
                } else {
                    $lainnyaCount += $item['count'];
                }
            }

            if ($lainnyaCount > 0) {
                $result[] = [
                    'id' => 0,
                    'name' => 'Lainnya',
                    'count' => $lainnyaCount,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('[AdminDashboard] getProductsByCategory failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Get recent orders
     *
     * Zero-downtime compatible:
     * - If jasa_order_items table exists, use join to jasa_order_items
     * - If not exists, fallback to query orders directly
     * - orders.jasa_id kept as legacy column for backward compatibility
     */
    private function getRecentOrders(): array
    {
        try {
            return \App\Models\Order::with(['merchant', 'jasaItems'])
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(function ($order) {
                    $jasaItem = $order->jasaItems->first();

                    return [
                        'id' => $order->id,
                        'order_code' => $order->order_code,
                        'nama' => $order->user_name_snapshot ?? $order->nama ?? 'Customer',
                        'tel' => $order->user_phone_snapshot ?? $order->tel ?? '-',
                        'tanggal' => $order->created_at->format('Y-m-d'),
                        'waktu' => $order->created_at->format('H:i:s'),
                        'total' => (int) (
                            $order->total_payment_snapshot
                            ?? $order->gross_amount
                            ?? $order->total
                            ?? $order->total_price
                            ?? 0
                        ),
                        'merchant' => [
                            'id' => $order->merchant_id,
                            'name' => $order->merchant ? $order->merchant->name : 'Unknown Merchant',
                        ],
                        'status' => $order->status,
                        'jasa' => $jasaItem ? [
                            'id' => $jasaItem->jasa_id,
                            'title' => $jasaItem->jasa_title_snapshot ?? $jasaItem->jasa_title ?? 'Layanan Jasa',
                        ] : null,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('[Orders] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent reports
     */
    private function getRecentReports(): array
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('content_reports')) {
                Log::warning('[AdminDashboard] Table "content_reports" does not exist');
                return [];
            }

            return ContentReport::with(['reporter:id,name', 'reason:id,reason_title'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($report) {
                    $reportableType = null;
                    if ($report->reportable_type) {
                        $reportableType = strtolower(class_basename($report->reportable_type));

                        if ($reportableType === 'communitypost') {
                            $reportableType = 'post';
                        } elseif ($reportableType === 'postcomment') {
                            $reportableType = 'post_comment';
                        } elseif ($reportableType === 'jasa') {
                            $reportableType = 'service';
                        }
                    }

                    return [
                        'id' => $report->id,
                        'status' => $report->status,
                        'report_comment' => $report->report_comment,
                        'reportable_type' => $reportableType,
                        'reporter' => $report->reporter ? [
                            'id' => $report->reporter->id,
                            'name' => $report->reporter->name
                        ] : null,
                        'reason' => $report->reason ? [
                            'id' => $report->reason->id,
                            'reason_title' => $report->reason->reason_title
                        ] : null,
                        'created_at' => $report->created_at->toIso8601String(),
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('[AdminDashboard] getRecentReports failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get orders & revenue statistics
     */
    /**
     * Get orders & revenue statistics
     *
     * Returns orders and revenue statistics for monthly, quarterly, or yearly period.
     *
     * @authenticated
     *
     * @queryParam period string required Period type (monthly, quarterly, yearly). Example: monthly
     * @queryParam year integer required Year for statistics. Example: 2025
     * @queryParam quarter integer Quarter (1-4), required if period=quarterly. Example: 2
     * @queryParam month integer Month (1-12), required if period=monthly. Example: 5
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "period": "monthly",
     *     "year": 2025,
     *     "quarter": null,
     *     "month": 5,
     *     "statistics": [ ... ],
     *     "has_data": true
     *   }
     * }
     * @response 500 {
     *   "success": false,
     *   "message": "Failed to load orders/revenue data"
     * }
     */
    public function ordersRevenue(Request $request)
    {
        $request->validate([
            'period' => 'required|in:monthly,quarterly,yearly',
            'year' => 'required|integer|min:2020|max:' . now()->year,
            'quarter' => 'nullable|required_if:period,quarterly|in:1,2,3,4',
            'month' => 'nullable|required_if:period,monthly|integer|min:1|max:12',
        ]);

        try {
            $data = match ($request->period) {
                'monthly' => $this->getMonthlyData($request->year, $request->month),
                'quarterly' => $this->getQuarterlyData($request->year, $request->quarter),
                'yearly' => $this->getYearlyData($request->year),
            };
            return response()->json([
                'success' => true,
                'data' => [
                    'period' => $request->period,
                    'year' => $request->year,
                    'quarter' => $request->quarter,
                    'month' => $request->month,
                    'statistics' => $data,
                    'has_data' => !empty($data),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[AdminDashboard] ordersRevenue failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load orders/revenue data'], 500);
        }
    }

    /**
     * Get monthly data (Week 1-4)
     */
    private function getMonthlyData(int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $weeks = [];
        for ($i = 1; $i <= 4; $i++) {
            $weekStart = $startDate->copy()->addWeeks($i - 1);
            $weekEnd = $weekStart->copy()->endOfWeek()->min($endDate);

            try {
                $orders = Order::whereBetween('created_at', [$weekStart, $weekEnd])->where('status', 'completed')->count();
                $revenue = Order::whereBetween('created_at', [$weekStart, $weekEnd])->where('status', 'completed')->sum('gross_amount');
            } catch (\Exception $e) {
                $orders = 0;
                $revenue = 0;
            }

            $weeks[] = [
                'label' => "Minggu {$i}",
                'period' => $weekStart->format('d M') . ' - ' . $weekEnd->format('d M'),
                'orders' => $orders,
                'revenue' => (float) $revenue,
            ];
        }

        return $weeks;
    }

    /**
     * Get quarterly data (Month 1-3)
     */
    private function getQuarterlyData(int $year, int $quarter): array
    {
        $startMonth = ($quarter - 1) * 3 + 1;
        $months = [];

        for ($i = 0; $i < 3; $i++) {
            $currentMonth = $startMonth + $i;
            $startDate = Carbon::create($year, $currentMonth, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            try {
                $orders = Order::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completed')->count();
                $revenue = Order::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completed')->sum('gross_amount');
            } catch (\Exception $e) {
                $orders = 0;
                $revenue = 0;
            }

            $months[] = [
                'label' => $startDate->format('M Y'),
                'period' => $startDate->format('F Y'),
                'orders' => $orders,
                'revenue' => (float) $revenue,
            ];
        }

        return $months;
    }

    /**
     * Get yearly data (Month 1-12)
     */
    private function getYearlyData(int $year): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            try {
                $orders = Order::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completed')->count();
                $revenue = Order::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completed')->sum('gross_amount');
            } catch (\Exception $e) {
                $orders = 0;
                $revenue = 0;
            }

            $months[] = [
                'label' => $startDate->format('M'),
                'period' => $startDate->format('F Y'),
                'orders' => $orders,
                'revenue' => (float) $revenue,
            ];
        }

        return $months;
    }

    /**
     * Calculate growth percentage
     */
    private function calculateGrowth(array $data): array
    {
        if (count($data) < 2) {
            return ['orders_growth' => 0, 'revenue_growth' => 0];
        }

        $latest = end($data);
        $previous = prev($data);

        $ordersGrowth = $previous['orders'] > 0 ? round((($latest['orders'] - $previous['orders']) / $previous['orders']) * 100, 1) : 0;
        $revenueGrowth = $previous['revenue'] > 0 ? round((($latest['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 1) : 0;

        return ['orders_growth' => $ordersGrowth, 'revenue_growth' => $revenueGrowth];
    }

    /**
     * Export dashboard statistics to PDF
     *
     * @authenticated
     *
     * @queryParam period string Period for statistics (all_time, last_30_days). Example: last_30_days
     *
     * @response 200 application/pdf
     */
    public function exportPdf(Request $request)
    {
        try {
            $period = $request->input('period', 'last_30_days');
            $user = $request->user();

            // Collect all dashboard data
            $data = [
                'overview' => $this->getOverviewStats($period),
                'products' => [
                    'total' => Product::where('status', 'published')->count(),
                    'by_category' => $this->getProductsByCategory(),
                ],
                'merchants' => [
                    'total' => Merchant::where('status', 'approved')->count(),
                    'by_segmentation' => $this->getMerchantsBySegmentation(),
                ],
                'recent_orders' => $this->getRecentOrders(),
                'recent_reports' => $this->getRecentReports(),
            ];

            // Add metadata
            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $user->name ?? 'Admin',
                'generated_by_email' => $user->email ?? '-',
                'period' => $period === 'last_30_days' ? '30 Hari Terakhir' : 'Semua Waktu',
                'period_start' => $period === 'last_30_days' ? Carbon::now()->subDays(30)->format('d F Y') : '-',
                'period_end' => now()->format('d F Y'),
            ];

            // Load logo as base64
            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';

            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            } else {
                Log::warning('[AdminDashboard] Logo file not found at: ' . $logoPath);
            }

            // Generate PDF
            $pdf = Pdf::loadView('exports.admin.admin-dashboard', [
                'data' => $data,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'portrait')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'dashboard-report-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminDashboard] Export PDF failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan PDF',
            ], 500);
        }
    }
}
