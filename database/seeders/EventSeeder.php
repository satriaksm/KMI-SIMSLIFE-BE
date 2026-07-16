<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use App\Models\Merchant;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding events beserta merchant...');

        // Bersihkan tabel agar tidak terjadi duplikasi saat seeder dijalankan berulang kali
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        \App\Models\Event::truncate();
        \Illuminate\Support\Facades\DB::table('event_merchants')->truncate();
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Create dummy banner if not exists
        if (!Storage::disk('public')->exists('events/dummy-banner.jpg')) {
            $img = imagecreatetruecolor(800, 400);
            $bg = imagecolorallocate($img, 220, 220, 220);
            imagefill($img, 0, 0, $bg);
            $textColor = imagecolorallocate($img, 50, 50, 50);
            imagestring($img, 5, 330, 190, 'Dummy Banner', $textColor);
            
            ob_start();
            imagejpeg($img);
            $imageContent = ob_get_clean();
            imagedestroy($img);
            
            Storage::disk('public')->put('events/dummy-banner.jpg', $imageContent);
        }

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



        // ✅ Event yang sedang aktif (published)
        Event::create([
            'event_name' => 'Bazar Ramadhan',
            'event_description' => 'Penjualan makanan berbuka puasa dan kebutuhan Ramadhan.',
            'event_start_date' => Carbon::today()->subDays(2),
            'event_end_date' => Carbon::today()->addDays(5),
            'banner_img_path' => 'events/dummy-banner.jpg',
            'status' => 'published',
            'created_by' => $admin->id,
        ]);

        // ✅ Event yang akan datang (draft)
        Event::create([
            'event_name' => 'Festival Produk Kreatif Kelurahan',
            'event_description' => 'Pameran produk kreatif serta pelatihan pemasaran digital bagi UMKM.',
            'event_start_date' => Carbon::today()->addDays(15),
            'event_end_date' => Carbon::today()->addDays(20),
            'banner_img_path' => 'events/dummy-banner.jpg',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        // ✅ Event yang sudah lewat (archived)
        Event::create([
            'event_name' => 'Pekan Kuliner Banyuanyar',
            'event_description' => 'Festival kuliner yang menghadirkan puluhan UMKM makanan dan minuman lokal.',
            'event_start_date' => Carbon::today()->subDays(15),
            'event_end_date' => Carbon::today()->subDays(5),
            'banner_img_path' => 'events/dummy-banner.jpg',
            'status' => 'archived',
            'created_by' => $admin->id,
        ]);

        // Attach merchants to the events
        $events = Event::all();
        foreach ($events as $event) {
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