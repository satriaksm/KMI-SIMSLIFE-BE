<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Event;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\Order;
use App\Models\ProductOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class LargeEventSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating Large Event (>50 days, >15 merchants)...');

        $startDate = Carbon::now()->subDays(35);
        $endDate = Carbon::now()->addDays(25); // 60 days total

        // 1. Create Event
        $event = Event::create([
            'event_name' => 'Mega Festival UMKM Nusantara ' . rand(1000, 9999),
            'event_description' => 'Event terbesar untuk UMKM dengan durasi panjang dan partisipasi massal.',
            'event_start_date' => $startDate->format('Y-m-d'),
            'event_end_date' => $endDate->format('Y-m-d'),
            'status' => 'published',
            'banner_img_path' => 'events/banners/dummy-banner.jpg',
            'created_by' => 1,
        ]);

        // 2. Ensure > 15 Merchants
        $merchantsCount = Merchant::count();
        if ($merchantsCount < 16) {
            $this->command->info('Creating additional merchants...');
            try {
                Merchant::factory(16 - $merchantsCount)->create();
            } catch (\Exception $e) {
                $this->command->warn('Factory failed, using existing merchants.');
            }
        }
        $merchants = Merchant::take(16)->get();

        // Attach merchants to event
        foreach ($merchants as $merchant) {
            $event->merchants()->syncWithoutDetaching([
                $merchant->id => [
                    'status' => 'accepted',
                ]
            ]);
        }

        // 3. Create Categories and Products
        $categories = Category::all();
        if ($categories->isEmpty()) {
            $categories = collect([
                Category::create(['name' => 'Makanan', 'slug' => 'makanan']),
                Category::create(['name' => 'Minuman', 'slug' => 'minuman']),
                Category::create(['name' => 'Kriya', 'slug' => 'kriya'])
            ]);
        }

        $this->command->info('Setting up products and categories...');
        foreach ($merchants as $merchant) {
            // Ensure merchant has products
            $products = Product::where('merchant_id', $merchant->id)->get();
            if ($products->isEmpty()) {
                for ($i = 1; $i <= 3; $i++) {
                    $prod = Product::create([
                        'merchant_id' => $merchant->id,
                        'name' => 'Produk ' . $merchant->name . ' ' . $i,
                        'slug' => Str::slug('Produk ' . $merchant->name . ' ' . $i . ' ' . Str::random(4)),
                        'description' => 'Deskripsi produk berkualitas dari UMKM unggulan.',
                        'price' => rand(1, 10) * 10000,
                        'status' => 'active'
                    ]);
                    // Attach category
                    DB::table('categorizables')->insert([
                        'category_id' => $categories->random()->id,
                        'categorizable_id' => $prod->id,
                        'categorizable_type' => 'App\\Models\\Product',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $products->push($prod);
                }
            } else {
                // Attach category if empty
                foreach ($products as $prod) {
                    $hasCategory = DB::table('categorizables')
                        ->where('categorizable_id', $prod->id)
                        ->where('categorizable_type', 'App\\Models\\Product')
                        ->exists();
                    if (!$hasCategory) {
                        DB::table('categorizables')->insert([
                            'category_id' => $categories->random()->id,
                            'categorizable_id' => $prod->id,
                            'categorizable_type' => 'App\\Models\\Product',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        // 4. Create Orders across the timeline
        $buyers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->take(10)->get();
        if ($buyers->isEmpty()) {
            $this->command->warn('No customers found. Creating a dummy customer...');
            try {
                $buyer = User::factory()->create(['name' => 'Dummy Customer', 'email' => 'dummy@cust.com']);
                DB::table('model_has_roles')->insert([
                    'role_id' => DB::table('roles')->where('name', 'customer')->value('id'),
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $buyer->id
                ]);
                $buyers->push($buyer);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $this->command->info('Creating transactions across the timeline...');
        if ($buyers->isNotEmpty()) {
            for ($d = 0; $d <= 35; $d++) { // Up to today (35 days from start)
                $currentDate = clone $startDate;
                $currentDate->addDays($d);
                
                // Random 2 to 5 orders per day
                $ordersPerDay = rand(2, 5);
                for ($o = 0; $o < $ordersPerDay; $o++) {
                    $merchant = $merchants->random();
                    $buyer = $buyers->random();
                    $orderTime = $currentDate->copy()->addMinutes(rand(0, 1400));
                    
                    $subtotal = rand(2, 10) * 10000;
                    $status = rand(1, 10) <= 8 ? 'completed' : 'cancelled';
                    
                    $order = Order::create([
                        'user_id' => $buyer->id,
                        'merchant_id' => $merchant->id,
                        'order_type' => 'takeaway',
                        'order_code' => 'EVT-LARGE-'.strtoupper(Str::random(10)) . rand(100, 999),
                        'subtotal' => $subtotal,
                        'discount_total' => 0,
                        'platform_fee' => 1000,
                        'gross_amount' => $subtotal + 1000,
                        'net_amount' => $subtotal,
                        'delivery_type' => 'pickup',
                        'payment_method' => rand(0, 1) ? 'gopay' : 'qris',
                        'status' => $status,
                        'created_at' => $orderTime,
                        'updated_at' => $orderTime,
                        'completed_at' => $status === 'completed' ? $orderTime->copy()->addMinutes(30) : null,
                        'cancelled_at' => $status === 'cancelled' ? $orderTime->copy()->addMinutes(5) : null,
                        'user_name_snapshot' => $buyer->name,
                        'user_phone_snapshot' => $buyer->phone ?? '081234567890',
                        'address_detail_snapshot' => 'Bazar Location',
                        'province_name_snapshot' => 'Jawa Tengah',
                        'city_name_snapshot' => 'Surakarta',
                        'district_name_snapshot' => 'Banjarsari',
                        'village_name_snapshot' => 'Banyuanyar',
                    ]);
                    
                    // Create Order Items
                    $products = Product::where('merchant_id', $merchant->id)->get();
                    if ($products->isNotEmpty()) {
                        $product = $products->random();
                        $qty = rand(1, 3);
                        $price = $product->price ?? (rand(1, 5) * 10000);
                        
                        ProductOrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_name_snapshot' => $product->name,
                            'image_snapshot_path' => 'products/dummy.jpg',
                            'quantity' => $qty,
                            'unit_price_snapshot' => $price,
                            'subtotal_snapshot' => $price * $qty,
                            'created_at' => $orderTime,
                            'updated_at' => $orderTime,
                        ]);
                    }
                    
                    // Rating
                    if ($status === 'completed' && rand(1, 10) <= 7) {
                        \App\Models\Rating::create([
                            'user_id' => $buyer->id,
                            'merchant_id' => $merchant->id,
                            'order_id' => $order->id,
                            'rateable_id' => $merchant->id,
                            'rateable_type' => Merchant::class,
                            'rating' => rand(3, 5),
                            'comment' => 'Bagus!',
                            'created_at' => $orderTime->copy()->addHours(1),
                            'updated_at' => $orderTime->copy()->addHours(1),
                        ]);
                    }
                }
            }
        }

        $this->command->info('Large Event Seeder completed successfully!');
    }
}
