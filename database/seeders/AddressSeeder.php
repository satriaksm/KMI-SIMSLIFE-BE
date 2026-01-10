<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Merchant;

class AddressSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'latitude' => -7.5420536,
                'longitude' => 110.8082958,
            ],
            [
                'latitude' => -7.5415871,
                'longitude' => 110.8084674,
            ],
            [
                'latitude' => -7.540513,
                'longitude' => 110.8085715,
            ],
            [
                'latitude' => -7.5402292,
                'longitude' => 110.8083753,
            ],
            [
                'latitude' => -7.5414246,
                'longitude' => 110.8101074,
            ],
            [
                'latitude' => -7.5413632,
                'longitude' => 110.8103515,
            ],
        ];

        $merchants = Merchant::all();

        foreach ($merchants as $index => $merchant) {
            $merchant->addresses()->create([
                'province_id' => 1,
                'city_id' => 1,
                'district_id' => 1,
                'village_id' => 1,
                'latitude' => $locations[$index]['latitude'],
                'longitude' => $locations[$index]['longitude'],
                'detail' => '',
                'label' => 'Lokasi UMKM',
            ]);
        }
    }
}
