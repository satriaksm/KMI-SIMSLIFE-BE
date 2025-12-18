<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Jasa;
use App\Models\Conversation;
use App\Models\Message;

class ChatSeeder extends Seeder
{
    public function run(): void
    {
        // Ambil atau buat satu user pembeli demo
        $buyer = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            [
                'name' => 'Demo Customer',
                'password' => bcrypt('123123123'),
                'email_verified_at' => now(),
            ]
        );

        // Pastikan punya peran customer (jika relasi roles ada)
        if (method_exists($buyer, 'roles')) {
            $customerRole = \App\Models\Role::firstOrCreate(['name' => 'customer']);
            $buyer->roles()->syncWithoutDetaching([$customerRole->id]);
        }

        // Ambil satu merchant (punya user umkm-owner)
        $merchant = Merchant::first();
        if (! $merchant) {
            $this->command?->warn('Tidak ada merchant, lewati ChatSeeder.');
            return;
        }

        // Ambil satu jasa milik merchant tersebut (atau jasa pertama kalau belum ada relasi)
        $jasa = Jasa::where('merchant_id', $merchant->id)->first() ?? Jasa::first();
        if (! $jasa) {
            $this->command?->warn('Tidak ada jasa, lewati ChatSeeder.');
            return;
        }

        // Buat atau ambil conversation antara buyer demo dan merchant untuk jasa ini
        $conversation = Conversation::firstOrCreate(
            [
                'buyer_id' => $buyer->id,
                'merchant_id' => $merchant->id,
                'jasa_id' => $jasa->id,
            ],
            [
                'status' => 'open',
                'last_message_at' => now(),
            ]
        );

        // Hapus pesan lama demo agar tidak dobel
        $conversation->messages()->delete();

        // Pesan awal dari pembeli
        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'sender_role' => 'buyer',
            'type' => 'message',
            'body' => 'Halo, saya tertarik dengan jasa ini. Apakah masih tersedia untuk minggu ini?',
        ]);

        // Balasan dari merchant (gunakan user pemilik merchant jika ada)
        $merchantOwner = $merchant->user ?? User::whereHas('roles', function ($q) {
            $q->where('name', 'umkm-owner');
        })->first();

        if ($merchantOwner) {
            $msg2 = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $merchantOwner->id,
                'sender_role' => 'merchant',
                'type' => 'message',
                'body' => 'Halo, tersedia. Boleh info kebutuhan detailnya? Kami bisa jadwalkan hari Sabtu.',
            ]);

            // Penawaran harga dari penjual
            $msg3 = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $merchantOwner->id,
                'sender_role' => 'merchant',
                'type' => 'offer',
                'body' => 'Untuk paket standar, kami bisa berikan harga khusus.',
                'jasa_id' => $jasa->id,
                'offer_price' => 150000,
                'offer_status' => 'pending',
            ]);

            // Simulasikan: pembeli menolak penawaran dan mengajukan harga balasan
            $msg3->offer_status = 'rejected';
            $msg3->save();

            // Counter-offer dari pembeli (dalam bentuk pesan biasa)
            $msg4 = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $buyer->id,
                'sender_role' => 'buyer',
                'type' => 'message',
                'body' => 'Terima kasih tawarannya, tapi saya hanya bisa di Rp130.000. Apakah bisa?',
            ]);
        }

        $conversation->update(['last_message_at' => now()]);

        $this->command?->info('ChatSeeder: contoh percakapan demo berhasil dibuat.');
    }
}
