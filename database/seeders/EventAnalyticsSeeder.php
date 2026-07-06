<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Event;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Voucher;
use App\Models\Order;
use App\Models\ProductOrderItem;
use App\Models\VoucherUsage;
use App\Models\Rating;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Str;

class EventAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding event analytics (orders, vouchers usage, ratings)...');

        $events = Event::all();
        if ($events->isEmpty()) {
            $this->command->warn('No events found. Skipping analytics seed.');
            return;
        }

        $buyers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->take(20)->get();
        if ($buyers->isEmpty()) {
            $this->command->warn('No customers found.');
            return;
        }

        $allVouchers = Voucher::where('voucher_status', 'active')->take(6)->get();

        foreach ($events as $event) {
            $merchants = $event->merchants()->wherePivot('status', 'accepted')->get();
            if ($merchants->isEmpty()) {
                $this->command->info("No accepted merchants found in event {$event->event_name}. Skipping.");
                continue;
            }

            // Attach some vouchers to the event if none exist
            $vouchers = $allVouchers->random(min(2, $allVouchers->count()));
            foreach ($vouchers as $voucher) {
                $voucher->update(['event_id' => $event->id]);
                foreach ($merchants as $merchant) {
                    $voucher->merchantsVoucher()->syncWithoutDetaching([
                        $merchant->id => ['status' => 'active']
                    ]);
                }
            }

            $startDate = Carbon::parse($event->event_start_date);
            
            $totalOrdersToCreate = rand(50, 150);
            
            for ($i = 0; $i < $totalOrdersToCreate; $i++) {
                $merchant = $merchants->random();
                $buyer = $buyers->random();
                
                // Random date within event duration (up to today if it's ongoing)
                $orderDate = $startDate->copy()->addMinutes(rand(0, 2880)); // Random up to 48 hours later
                
                // Generate basic order structure
                $statusOptions = ['completed', 'completed', 'completed', 'cancelled'];
                $status = $statusOptions[array_rand($statusOptions)];
                
                $subtotal = rand(2, 10) * 10000;
                
                $order = Order::create([
                    'user_id' => $buyer->id,
                    'merchant_id' => $merchant->id,
                    'order_type' => 'takeaway',
                    'order_code' => 'EVT-'.strtoupper(Str::random(6)),
                    'subtotal' => $subtotal,
                    'discount_total' => 0,
                    'platform_fee' => 1000,
                    'gross_amount' => $subtotal + 1000,
                    'net_amount' => $subtotal,
                    'delivery_type' => 'pickup',
                    'payment_method' => rand(0, 1) ? 'gopay' : 'qris',
                    'status' => $status,
                    'created_at' => $orderDate,
                    'updated_at' => $orderDate->copy()->addMinutes(15),
                    'completed_at' => $status === 'completed' ? $orderDate->copy()->addMinutes(30) : null,
                    'cancelled_at' => $status === 'cancelled' ? $orderDate->copy()->addMinutes(5) : null,
                    'user_name_snapshot' => $buyer->name,
                    'user_phone_snapshot' => $buyer->phone ?? '081234567890',
                    'address_detail_snapshot' => 'Bazar Location',
                    'province_name_snapshot' => 'Jawa Tengah',
                    'city_name_snapshot' => 'Surakarta',
                    'district_name_snapshot' => 'Banjarsari',
                    'village_name_snapshot' => 'Banyuanyar',
                    'latitude_snapshot' => '-7.556',
                    'longitude_snapshot' => '110.831',
                ]);

                // Assign voucher 30% of the time
                if (rand(1, 10) <= 3 && $vouchers->isNotEmpty()) {
                    $voucher = $vouchers->random();
                    $discountAmount = $voucher->voucher_type === 'percent' 
                        ? ($subtotal * ($voucher->value / 100))
                        : $voucher->value;
                    
                    if ($voucher->max_discount_amount && $discountAmount > $voucher->max_discount_amount) {
                        $discountAmount = $voucher->max_discount_amount;
                    }

                    $order->update([
                        'voucher_id' => $voucher->id,
                        'discount_total' => $discountAmount,
                        'gross_amount' => $order->gross_amount - $discountAmount,
                    ]);

                    VoucherUsage::create([
                        'user_id' => $buyer->id,
                        'voucher_id' => $voucher->id,
                        'order_id' => $order->id,
                        'discount_amount' => $discountAmount,
                        'created_at' => $orderDate,
                        'updated_at' => $orderDate,
                    ]);
                }

                // Create Order Items
                $products = Product::where('merchant_id', $merchant->id)->take(3)->get();
                if ($products->isNotEmpty()) {
                    $itemsCount = rand(1, 3);
                    for ($j = 0; $j < $itemsCount; $j++) {
                        $product = $products->random();
                        $qty = rand(1, 3);
                        $price = rand(1, 5) * 10000;
                        
                        ProductOrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_name_snapshot' => $product->name,
                            'image_snapshot_path' => 'products/dummy.jpg',
                            'quantity' => $qty,
                            'unit_price_snapshot' => $price,
                            'subtotal_snapshot' => $price * $qty,
                            'created_at' => $orderDate,
                            'updated_at' => $orderDate,
                        ]);
                    }
                }

                // Create Rating 60% of the time for completed orders
                if ($status === 'completed' && rand(1, 10) <= 6) {
                    Rating::create([
                        'user_id' => $buyer->id,
                        'merchant_id' => $merchant->id,
                        'order_id' => $order->id,
                        'rateable_id' => $merchant->id,
                        'rateable_type' => Merchant::class,
                        'rating' => rand(3, 5), // Mostly good ratings
                        'comment' => ['Mantap', 'Enak sekali', 'Pelayanan cepat', 'Sesuai pesanan'][rand(0,3)],
                        'created_at' => $orderDate->copy()->addHours(1),
                        'updated_at' => $orderDate->copy()->addHours(1),
                    ]);
                }
            }
        }
        
        $this->command->info('Event analytics seeded successfully!');
    }
}
