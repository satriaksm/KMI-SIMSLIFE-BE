<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

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

        // ✅ Event yang sedang aktif (published)
        Event::create([
            'event_name' => 'Event Aktif - Promo Akhir Tahun 2025',
            'event_description' => 'Event promo yang sedang berlangsung',
            'event_start_date' => Carbon::today()->subDays(2),
            'event_end_date' => Carbon::today()->addDays(5),
            'status' => 'published',
            'created_by' => $admin->id,
        ]);

        // ✅ Event yang akan datang (draft)
        Event::create([
            'event_name' => 'Event Mendatang - Flash Sale Februari',
            'event_description' => 'Event yang akan dimulai bulan depan',
            'event_start_date' => Carbon::today()->addDays(15),
            'event_end_date' => Carbon::today()->addDays(20),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        // ✅ Event yang sudah lewat (archived)
        Event::create([
            'event_name' => 'Event Berakhir - Promo Natal 2024',
            'event_description' => 'Event yang sudah berakhir',
            'event_start_date' => Carbon::today()->subDays(15),
            'event_end_date' => Carbon::today()->subDays(5),
            'status' => 'archived',
            'created_by' => $admin->id,
        ]);

        // Generate random events dengan status yang sesuai
        Event::factory()->active()->count(2)->create();
        Event::factory()->upcoming()->count(3)->create();
        Event::factory()->past()->count(2)->create();

        $this->command->info('Event beserta merchant berhasil di-seed!');
    }
}