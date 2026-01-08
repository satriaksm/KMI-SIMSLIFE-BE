<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Event;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Str;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding events beserta merchant...');

        // Ambil admin sebagai creator event
        $admin = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();
        if (!$admin) {
            $this->command->warn('Admin user not found. Jalankan UserSeeder dulu.');
            return;
        }

        // Ambil merchant yang sudah approved
        $merchants = Merchant::where('status', 'approved')->get();
        if ($merchants->isEmpty()) {
            $this->command->warn('No approved merchants found. Jalankan MerchantSeeder dulu.');
            return;
        }

        // Buat 5 event
        foreach (range(1, 5) as $i) {
            $event = Event::create([
                'event_name' => "Event {$i} - " . Str::random(6),
                'event_description' => "Deskripsi event ke-{$i}",
                'event_start_date' => now()->addDays($i * 2),
                'event_end_date' => now()->addDays($i * 2 + 3),
                'status' => 'published',
                'created_by' => $admin->id,
            ]);

            // Pilih 3-6 merchant random untuk event ini
            $eventMerchants = $merchants->random(min(6, max(3, $merchants->count())));
            $attachData = [];
            foreach ($eventMerchants as $merchant) {
                $attachData[$merchant->id] = ['status' => 'accepted'];
            }
            $event->merchants()->attach($attachData);

            $this->command->info("Event {$event->event_name} dibuat dengan " . count($attachData) . " merchant.");
        }

        $this->command->info('Event beserta merchant berhasil di-seed!');
    }
}