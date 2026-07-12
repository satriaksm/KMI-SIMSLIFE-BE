<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\ProductOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Payment;
use App\Models\Address;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating orders...');

        $users = User::all();
        $merchants = Merchant::with(['products.variants'])->get();

        if ($merchants->isEmpty() || $users->isEmpty()) {
            $this->command->warn('No merchants or users found. Run MerchantSeeder and UserSeeder first.');
            return;
        }

        $totalOrders = 0;
        $faker = \Faker\Factory::create();
        
        $statuses = ['pending', 'on-progress', 'completed', 'completed', 'completed', 'completed', 'cancelled', 'rejected', 'ready_to_pickup'];
        
        foreach (range(1, 400) as $i) {
            $user = $users->random();
            $merchant = $merchants->random();

            if ($merchant->products->isEmpty()) {
                continue;
            }

            $products = $merchant->products->random(rand(1, min(3, $merchant->products->count())));
            $subtotal = 0;
            
            $randomDate = $faker->dateTimeBetween('2026-01-01', '2026-07-12');
            $status = $faker->randomElement($statuses);
            
            $orderCode = 'ORD-' . strtoupper(Str::random(8));
            $paymentMethod = $faker->randomElement(['cod', 'transfer']);
            
            $address = Address::where('addressable_type', 'user')->where('addressable_id', $user->id)->first();

            $order = Order::create([
                'order_code' => $orderCode,
                'user_id' => $user->id,
                'merchant_id' => $merchant->id,
                'address_id' => $address ? $address->id : null,
                'order_type' => 'product',
                'delivery_type' => $faker->randomElement(['pickup', 'delivery']),
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentMethod === 'transfer' ? 'paid' : 'unpaid',
                'subtotal' => 0, 
                'discount_total' => 0,
                'delivery_fee_snapshot' => 10000,
                'platform_fee' => 2000,
                'gross_amount' => 0,
                'net_amount' => 0,
                'status' => $status,
                'user_name_snapshot' => $user->name,
                'user_phone_snapshot' => $user->phone ?? '08123456789',
                'address_detail_snapshot' => $address?->address ?? $faker->address,
                'province_name_snapshot' => 'Jawa Tengah',
                'city_name_snapshot' => 'Surakarta',
                'district_name_snapshot' => 'Banjarsari',
                'village_name_snapshot' => 'Banyuanyar',
                'created_at' => $randomDate,
                'updated_at' => clone $randomDate,
            ]);
            
            // Advance updated_at for some statuses
            if ($status !== 'pending') {
                $order->updated_at = Carbon::instance($randomDate)->addHours(rand(1, 48));
                if ($status === 'completed') {
                    $order->completed_at = $order->updated_at;
                } elseif ($status === 'cancelled') {
                    $order->cancelled_at = $order->updated_at;
                }
                $order->save();
            }

            foreach ($products as $product) {
                if ($product->variants->isEmpty()) continue;
                $variant = $product->variants->random();
                $qty = rand(1, 3);
                $itemSubtotal = $variant->price * $qty;
                $subtotal += $itemSubtotal;

                ProductOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $qty,
                    'product_name_snapshot' => $product->name,
                    'product_variant_snapshot' => $variant->name ?? 'Default',
                    'unit_price_snapshot' => $variant->price,
                    'subtotal_snapshot' => $itemSubtotal,
                    'sku_snapshot' => $variant->sku ?? 'SKU-'.rand(1000, 9999),
                    'image_snapshot_path' => 'default.png',
                    'created_at' => clone $randomDate,
                    'updated_at' => clone $randomDate,
                ]);
            }

            if ($subtotal == 0) {
                $order->delete();
                continue;
            }
            
            $grossAmount = $subtotal + 10000 + 2000;
            $netAmount = $subtotal; // Asumsikan bersih merchant

            $order->update([
                'subtotal' => $subtotal,
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
            ]);
            
            // Buat payment jika Transfer
            if ($paymentMethod === 'transfer') {
                $paymentStatus = 'PAID';
                $refundStatus = null;
                $paidAt = Carbon::instance($randomDate)->addMinutes(rand(5, 60));
                
                if ($status === 'cancelled') {
                    if (rand(1, 100) > 50) {
                        $refundStatus = $faker->randomElement(['processing', 'succeeded', 'failed']);
                    }
                }
                
                Payment::create([
                    'order_id' => $order->id,
                    'external_id' => 'EXT-' . $orderCode,
                    'xendit_invoice_id' => 'INV-' . Str::random(10),
                    'xendit_refund_id' => $refundStatus ? 'RFND-' . Str::random(10) : null,
                    'invoice_url' => 'https://checkout-staging.xendit.co/web/' . Str::random(10),
                    'payment_method' => 'BANK_TRANSFER',
                    'amount' => $grossAmount,
                    'status' => $paymentStatus,
                    'refund_status' => $refundStatus,
                    'paid_at' => $paidAt,
                    'expired_at' => Carbon::instance($randomDate)->addDay(),
                    'created_at' => clone $randomDate,
                    'updated_at' => clone $randomDate,
                ]);
            }
            
            $totalOrders++;
        }

        $this->command->info("Orders seeded successfully!");
        $this->command->info("   Total: {$totalOrders}");
    }
}
