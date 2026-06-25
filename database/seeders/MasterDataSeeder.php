<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MasterDataSeeder extends Seeder
{
    private const BASE = 'https://ibnux.github.io/data-indonesia';
    private const HTTP_TIMEOUT_SECONDS = 60;

    public function run(): void
    {
        DB::disableQueryLog();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // truncate any tables that reference wilayah tables to avoid FK constraint issues
        DB::table('addresses')->truncate();
        DB::table('villages')->truncate();
        DB::table('districts')->truncate();
        DB::table('cities')->truncate();
        DB::table('provinces')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        // ============================================================
        // 1. PROVINCES
        // ============================================================
        $this->info('Seeding Provinces...');
        try {
            $provinces = $this->fetchJson(self::BASE . '/provinsi.json');
            $provinceRows = array_map(fn($p) => [
                'id' => (int) $p['id'],
                'name' => $p['nama'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $provinces);
            DB::table('provinces')->insert($provinceRows);
        } catch (\Exception $e) {
            $this->info("Failed to fetch provinces: " . $e->getMessage());
        }
        $this->info('Provinces total: ' . DB::table('provinces')->count());

        // ============================================================
        // 2. CITIES
        // ============================================================
        $this->info('Seeding Cities...');
        $provincesData = DB::table('provinces')->get(['id']);
        foreach ($provincesData as $province) {
            try {
                $cities = $this->fetchJson(self::BASE . '/kabupaten/' . $province->id . '.json');
                $cityRows = array_map(fn($c) => [
                    'id' => (int) $c['id'],
                    'province_id' => $province->id,
                    'name' => $c['nama'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $cities);
                if (!empty($cityRows)) {
                    DB::table('cities')->insert($cityRows);
                }
            } catch (\Exception $e) {
                $this->info("Warning: Failed to fetch cities for province {$province->id}");
            }
        }
        $this->info('Cities total: ' . DB::table('cities')->count());

        // ============================================================
        // 3. DISTRICTS
        // ============================================================
        $this->info('Seeding Districts...');
        $citiesData = DB::table('cities')->get(['id']);
        foreach ($citiesData as $city) {
            try {
                $districts = $this->fetchJson(self::BASE . '/kecamatan/' . $city->id . '.json');
                $districtRows = array_map(fn($d) => [
                    'id' => (int) $d['id'],
                    'city_id' => $city->id,
                    'name' => $d['nama'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $districts);
                if (!empty($districtRows)) {
                    DB::table('districts')->insert($districtRows);
                }
            } catch (\Exception $e) {
                $this->info("Warning: Failed to fetch districts for city {$city->id}");
            }
        }
        $this->info('Districts total: ' . DB::table('districts')->count());

        // ============================================================
        // 4. VILLAGES
        // ============================================================
        $this->info('Seeding Villages (Fetching ~7000+ districts, this might take a while on first run)...');
        $districtsData = DB::table('districts')->get(['id']);
        $totalDistricts = count($districtsData);

        foreach ($districtsData as $index => $district) {
            try {
                $villages = $this->fetchJson(self::BASE . '/kelurahan/' . $district->id . '.json');
                $villageRows = array_map(fn($v) => [
                    'id' => (int) $v['id'],
                    'district_id' => $district->id,
                    'name' => $v['nama'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $villages);

                if (!empty($villageRows)) {
                    foreach (array_chunk($villageRows, 500) as $chunk) {
                        DB::table('villages')->insert($chunk);
                    }
                }
            } catch (\Exception $e) {
                $this->info("Warning: Failed to fetch villages for district {$district->id}");
            }

            if (($index + 1) % 500 === 0) {
                $this->info("Processed " . ($index + 1) . " of {$totalDistricts} districts...");
            }
        }

        $this->info('Villages total: ' . DB::table('villages')->count());
        $this->info('✅ Seluruh data wilayah Indonesia berhasil di-seed.');
    }

    // ============================================================
    // FETCH + CACHE
    // ============================================================
    private function fetchJson(string $url): array
    {
        $cacheDir = storage_path('app/wilayah-cache');

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        $cacheKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $url);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';

        if (is_file($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        $resp = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->retry(3, 500)
            ->get($url);

        if ($resp->failed()) {
            throw new \RuntimeException("Failed requesting: {$url}");
        }

        $json = $resp->json() ?? [];

        file_put_contents($cacheFile, json_encode($json));

        return $json;
    }

    private function info(string $message): void
    {
        if (app()->runningInConsole()) {
            $this->command?->info($message);
        }
    }
}