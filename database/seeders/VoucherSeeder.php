<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Voucher;
use App\Models\Event;

class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $event = Event::first();
        if ($event) {
            Voucher::factory()->count(3)->create([
                'event_id' => $event->id,
                'merchant_id' => null, // voucher event
            ]);
        }
    }
}