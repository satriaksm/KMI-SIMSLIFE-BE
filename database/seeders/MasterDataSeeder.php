<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MasterDataSeeder extends Seeder
{
    private const BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api';

    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('villages')->truncate();
        DB::table('districts')->truncate();
        DB::table('cities')->truncate();
        DB::table('provinces')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        DB::beginTransaction();
        try {
            // Province
            $provinceId = DB::table('provinces')->insertGetId([
                'name' => 'Jawa Tengah',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // City (Kota Surakarta)
            $cityId = DB::table('cities')->insertGetId([
                'province_id' => $provinceId,
                'name' => 'Kota Surakarta',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // District (Banjarsari)
            $districtId = DB::table('districts')->insertGetId([
                'city_id' => $cityId,
                'name' => 'Banjarsari',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Village (Banyuanyar)
            DB::table('villages')->insert([
                'district_id' => $districtId,
                'name' => 'Banyuanyar',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::commit();
            $this->info('Master wilayah minimal (Jateng/Surakarta/Banjarsari/Banyuanyar) tersimpan.');
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        // Hapus data lama dengan urutan aman FK
        // DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // DB::table('villages')->truncate();
        // DB::table('districts')->truncate();
        // DB::table('cities')->truncate();
        // DB::table('provinces')->truncate();
        // DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // $now = now();

        // // 1) Provinces
        // $provinces = $this->fetchJson(self::BASE . '/provinces.json');
        // $provinceRows = array_map(fn($p) => [
        //     'id' => (int) $p['id'],
        //     'name' => $p['name'],
        //     'created_at' => $now,
        //     'updated_at' => $now,
        // ], $provinces);

        // foreach (array_chunk($provinceRows, 500) as $chunk) {
        //     DB::table('provinces')->insert($chunk);
        // }
        // $this->info('Provinces seeded: ' . count($provinceRows));

        // // 2) Cities (pakai data "regencies" dari Emsifa)
        // foreach ($provinceRows as $prov) {
        //     $regencies = $this->fetchJson(self::BASE . '/regencies/' . $prov['id'] . '.json');
        //     $cityRows = array_map(fn($r) => [
        //         'id' => (int) $r['id'],      // gunakan ID Emsifa
        //         'province_id' => (int) $prov['id'],
        //         'name' => $r['name'],
        //         'created_at' => $now,
        //         'updated_at' => $now,
        //     ], $regencies);

        //     foreach (array_chunk($cityRows, 500) as $chunk) {
        //         DB::table('cities')->insert($chunk);
        //     }
        //     $this->info("Cities seeded for province {$prov['id']}: " . count($cityRows));
        // }

        // // 3) Districts (pakai "districts/{regencyId}")
        // $cityIds = DB::table('cities')->pluck('id')->all();
        // foreach ($cityIds as $cityId) {
        //     $districts = $this->fetchJson(self::BASE . '/districts/' . $cityId . '.json');
        //     $districtRows = array_map(fn($d) => [
        //         'id' => (int) $d['id'],
        //         'city_id' => (int) $cityId,        // catatan: di schema Anda kolomnya city_id
        //         'name' => $d['name'],
        //         'created_at' => $now,
        //         'updated_at' => $now,
        //     ], $districts);

        //     foreach (array_chunk($districtRows, 500) as $chunk) {
        //         DB::table('districts')->insert($chunk);
        //     }
        //     $this->info("Districts seeded for city {$cityId}: " . count($districtRows));
        // }

        // // 4) Villages (pakai "villages/{districtId}")
        // $districtIds = DB::table('districts')->pluck('id')->all();
        // foreach ($districtIds as $districtId) {
        //     $villages = $this->fetchJson(self::BASE . '/villages/' . $districtId . '.json');
        //     $villageRows = array_map(fn($v) => [
        //         'id' => (int) $v['id'],
        //         'district_id' => (int) $districtId,
        //         'name' => $v['name'],
        //         'created_at' => $now,
        //         'updated_at' => $now,
        //     ], $villages);

        //     foreach (array_chunk($villageRows, 500) as $chunk) {
        //         DB::table('villages')->insert($chunk);
        //     }
        //     $this->info("Villages seeded for district {$districtId}: " . count($villageRows));
        // }

        // $this->info('Wilayah seeding completed.');
    }

    private function fetchJson(string $url): array
    {
        $resp = Http::retry(5, 500)->get($url);
        if ($resp->failed()) {
            throw new \RuntimeException("Failed requesting: {$url}");
        }
        return $resp->json() ?? [];
    }

    private function info(string $message): void
    {
        if (app()->runningInConsole()) {
            $this->command?->info($message);
        }
    }
}
