<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$id = 6;
$event = \App\Models\Event::findOrFail($id);
$startDate  = $event->event_start_date;
$endDate    = $event->event_end_date;
$merchantIds = DB::table('event_merchants')->where('event_id', $id)->where('status', 'accepted')->pluck('merchant_id');
$voucherIds = [];
$ordersQuery = DB::table('orders')->where(function ($q) use ($voucherIds, $merchantIds, $startDate, $endDate) {
    $q->orWhere(function ($q2) use ($merchantIds, $startDate, $endDate) {
        $q2->whereIn('merchant_id', $merchantIds)->whereBetween('created_at', [$startDate, date('Y-m-d', strtotime($endDate . ' +1 day'))]);
    });
});
$allOrders = $ordersQuery->get();
$orderIds = $allOrders->pluck('id');

$topCategories = DB::table('product_order_items')
    ->join('orders', 'orders.id', '=', 'product_order_items.order_id')
    ->join('categorizables', function ($join) {
        $join->on('categorizables.categorizable_id', '=', 'product_order_items.product_id')
            ->whereIn('categorizables.categorizable_type', ['App\\Models\\Product', 'product']);
    })
    ->join('categories', 'categories.id', '=', 'categorizables.category_id')
    ->whereIn('product_order_items.order_id', $orderIds)
    ->where('orders.status', 'completed')
    ->selectRaw('categories.name as category_name, SUM(product_order_items.quantity) as total_qty, SUM(product_order_items.subtotal_snapshot) as total_revenue')
    ->groupBy('categories.name')
    ->orderByDesc('total_qty')
    ->get();
dump($topCategories);
