<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Jasa;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;

class ChatSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating sample chat conversations and messages...');

        // Get sample users and merchants with their jasas
        $merchants = Merchant::with('jasas')->get();
        $customers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->limit(5)->get();

        if ($merchants->isEmpty() || $customers->isEmpty()) {
            $this->command->warn('Not enough merchants or customers. Skipping chat seeding.');
            return;
        }

        // Filter merchants that have jasas
        $merchantsWithJasas = $merchants->filter(function ($merchant) {
            return $merchant->jasas && $merchant->jasas->count() > 0;
        });

        if ($merchantsWithJasas->isEmpty()) {
            $this->command->warn('No merchants with jasas found. Skipping chat seeding.');
            return;
        }

        // Sample messages from buyers
        $buyerMessages = [
            'Halo, apakah layanan ini tersedia hari ini?',
            'Berapa harga jika untuk 5 unit?',
            'Bisa buat konsultasi terlebih dahulu?',
            'Seberapa lama waktu pengerjaan?',
            'Ada garansi ngga untuk layanan ini?',
            'Lokasi saya di Jakarta Selatan, bisa handle?',
            'Kapan bisa datang?',
            'Saya butuh layanan ini segera, urgent.',
        ];

        // Sample messages from merchants
        $merchantMessages = [
            'Halo, terima kasih sudah menghubungi kami. Iya tersedia, kami buka sampai jam 21:00.',
            'Untuk pembelian dalam jumlah besar kami bisa berikan harga spesial. Berapa kebutuhan Anda?',
            'Tentu saja! Silakan hubungi kami untuk jadwal konsultasi.',
            'Rata-rata pengerjaan membutuhkan waktu 2-3 jam tergantung kondisi.',
            'Semua layanan kami dijamin kualitasnya. Kepuasan pelanggan adalah prioritas kami.',
            'Kami melayani daerah Jakarta Selatan dengan biaya service Rp 50.000. Tidak masalah!',
            'Kami bisa datang besok pagi, jam 09:00 OK?',
            'Siap melayani dengan secepatnya. Silakan konfirmasi detail kebutuhan Anda.',
        ];

        // Sample offers
        $offers = [
            ['price' => 150000, 'description' => 'Paket Standar - 3 jam service'],
            ['price' => 200000, 'description' => 'Paket Premium - 5 jam service + maintenance'],
            ['price' => 250000, 'description' => 'Paket VIP - Full service + garansi 1 bulan'],
            ['price' => 100000, 'description' => 'Konsultasi gratis + quotation'],
            ['price' => 300000, 'description' => 'Paket Tahunan - Maintenance bulanan'],
        ];

        $conversationCount = 0;

        // Create conversations for each combination of merchant and random customers
        foreach ($merchantsWithJasas as $merchant) {
            // Pick 3-5 random customers per merchant
            $selectedCustomers = $customers->random(min(rand(3, 5), $customers->count()));

            foreach ($selectedCustomers as $customer) {
                // Pick random jasa from this merchant
                if ($merchant->jasas->isEmpty()) {
                    continue;
                }

                $merchantJasa = $merchant->jasas->random();

                // Create conversation
                $conversation = Conversation::create([
                    'buyer_id' => $customer->id,
                    'merchant_id' => $merchant->id,
                    'jasa_id' => $merchantJasa->id,
                    'status' => $this->getRandomStatus(),
                    'last_message_at' => now()->subHours(rand(1, 72)),
                ]);

                $conversationCount++;

                // Create 3-8 messages per conversation
                $messageCount = rand(3, 8);
                $currentTime = now()->subHours(rand(24, 72));

                for ($i = 0; $i < $messageCount; $i++) {
                    // Alternate between buyer and merchant
                    $isBuyerMessage = $i % 2 === 0;

                    $messageData = [
                        'conversation_id' => $conversation->id,
                        'sender_id' => $isBuyerMessage ? $customer->id : $merchant->user_id,
                        'sender_role' => $isBuyerMessage ? 'buyer' : 'merchant',
                        'type' => 'text',
                        'body' => $isBuyerMessage
                            ? $buyerMessages[array_rand($buyerMessages)]
                            : $merchantMessages[array_rand($merchantMessages)],
                    ];

                    // Sometimes add an offer message from merchant
                    if (!$isBuyerMessage && rand(0, 3) === 0) {
                        $offer = $offers[array_rand($offers)];
                        $messageData['type'] = 'offer';
                        $messageData['body'] = 'Silakan lihat penawaran harga berikut: ' . $offer['description'];
                        $messageData['offer_price'] = $offer['price'];
                        $messageData['offer_status'] = $this->getRandomOfferStatus();
                    }

                    $messageData['created_at'] = $currentTime;
                    $messageData['updated_at'] = $currentTime;

                    Message::create($messageData);

                    // Increment time for next message
                    $currentTime = $currentTime->addMinutes(rand(5, 30));
                }

                // Update last_message_at
                $conversation->update([
                    'last_message_at' => $currentTime->subMinutes(5),
                ]);

                $this->command->line("✓ Created conversation #{$conversation->id} between {$customer->name} (buyer) and {$merchant->user->name} (merchant)");
            }
        }

        $this->command->info("✅ Successfully created {$conversationCount} sample conversations with messages!");
    }

    private function getRandomStatus(): string
    {
        $statuses = ['active', 'pending_offer', 'deal_accepted', 'completed'];
        return $statuses[array_rand($statuses)];
    }

    private function getRandomOfferStatus(): string
    {
        $statuses = ['pending', 'accepted', 'rejected'];
        return $statuses[array_rand($statuses)];
    }
}
