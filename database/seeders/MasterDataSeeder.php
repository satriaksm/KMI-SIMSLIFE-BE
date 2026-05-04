<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MasterDataSeeder extends Seeder
{
    private const BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api';
    private const HTTP_TIMEOUT_SECONDS = 60;

    public function run(): void
    {
        DB::disableQueryLog();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('villages')->truncate();
        DB::table('districts')->truncate();
        DB::table('cities')->truncate();
        DB::table('provinces')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        // ============================================================
        // 1. PROVINCE (JAWA TENGAH)
        // ============================================================
        $this->info('Seeding Jawa Tengah...');

        DB::table('provinces')->insert([
            'id' => 33,
            'name' => 'JAWA TENGAH',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ============================================================
        // 2. CITY (SURAKARTA ONLY)
        // ============================================================
        $this->info('Seeding Surakarta...');

        $city = [
            'id' => 3372,
            'province_id' => 33,
            'name' => 'KOTA SURAKARTA',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('cities')->insert([$city]);

        // ============================================================
        // 3. DISTRICTS (KECAMATAN SURAKARTA)
        // ============================================================
        $this->info('Seeding districts (Surakarta)...');

        $districts = $this->fetchJson(self::BASE . '/districts/3372.json');

        $districtRows = array_map(fn($d) => [
            'id' => (int) $d['id'],
            'city_id' => 3372,
            'name' => $d['name'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $districts);

        DB::table('districts')->insert($districtRows);

        $this->info('Districts total: ' . count($districtRows));

        // ============================================================
        // 4. VILLAGES (HANYA SURAKARTA)
        // ============================================================
        $this->info('Seeding villages (Surakarta only)...');

        foreach ($districtRows as $district) {
            $villages = $this->fetchJson(self::BASE . '/villages/' . $district['id'] . '.json');

            $villageRows = array_map(fn($v) => [
                'id' => (int) $v['id'],
                'district_id' => $district['id'],
                'name' => $v['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $villages);

            DB::table('villages')->insert($villageRows);
        }

        $this->info('Villages total: ' . DB::table('villages')->count());

        $this->info('✅ Surakarta seeding completed (finally fast, right?).');
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