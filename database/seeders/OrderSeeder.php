<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

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

        foreach (range(1, 100) as $i) {
            $user = $users->random();
            $merchant = $merchants->random();

            // Lewati merchant tanpa produk
            if ($merchant->products->isEmpty()) {
                continue;
            }

            // Pilih 1-3 produk dari merchant
            $products = $merchant->products->random(rand(1, min(3, $merchant->products->count())));
            $total = 0;

            $order = Order::create([
                'user_id' => $user->id,
                'merchant_id' => $merchant->id,
                'jasa_id' => null,
                'nama' => $user->name,
                'tel' => $user->phone ?? '08123456789',
                'alamat' => 'Alamat contoh',
                'tanggal' => now()->format('Y-m-d'),
                'waktu' => now()->format('H:i'),
                'metode_pembayaran' => 'COD',
                'total' => 0, // akan diupdate setelah item dibuat
            ]);

            foreach ($products as $product) {
                $variant = $product->variants->random();
                $qty = rand(1, 3);
                $subtotal = $variant->price * $qty;
                $total += $subtotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $qty,
                    'price' => $variant->price,
                    'subtotal' => $subtotal,
                ]);
            }

            $order->update(['total' => $total]);
            $totalOrders++;
        }

        $this->command->info("Orders seeded successfully!");
        $this->command->info("   Total: {$totalOrders}");
    }
}
