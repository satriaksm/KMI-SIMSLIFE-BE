<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MasterDataSeeder extends Seeder
{
    private const BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api';
    private const CHUNK_SIZE = 500;
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

        // 1) Provinces
        $this->info('Seeding provinces...');
        $provinces = $this->fetchJson(self::BASE . '/provinces.json');
        $provinceRows = array_map(fn($p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $provinces);

        foreach (array_chunk($provinceRows, self::CHUNK_SIZE) as $chunk) {
            DB::table('provinces')->insert($chunk);
        }
        $this->info('Provinces seeded: ' . count($provinceRows));

        // 2) Cities (Kab/Kota) from "regencies"
        $this->info('Seeding cities (kab/kota)...');
        foreach ($provinceRows as $prov) {
            $regencies = $this->fetchJson(self::BASE . '/regencies/' . $prov['id'] . '.json');
            $cityRows = array_map(fn($r) => [
                'id' => (int) $r['id'],
                'province_id' => (int) $prov['id'],
                'name' => $r['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $regencies);

            foreach (array_chunk($cityRows, self::CHUNK_SIZE) as $chunk) {
                DB::table('cities')->insert($chunk);
            }
            $this->info("Cities seeded for province {$prov['id']}: " . count($cityRows));
        }
        $this->info('Cities total: ' . DB::table('cities')->count());

        // 3) Districts (Kecamatan)
        $this->info('Seeding districts (kecamatan)...');
        $cityIds = DB::table('cities')->pluck('id')->all();
        $seenDistrictIds = [];
        foreach ($cityIds as $cityId) {
            $districts = $this->fetchJson(self::BASE . '/districts/' . $cityId . '.json');
            $districtRows = [];
            foreach ($districts as $d) {
                $districtId = (int) ($d['id'] ?? 0);
                if (!$districtId) {
                    continue;
                }
                if (isset($seenDistrictIds[$districtId])) {
                    continue;
                }
                $seenDistrictIds[$districtId] = true;
                $districtRows[] = [
                    'id' => $districtId,
                    'city_id' => (int) $cityId,
                    'name' => $d['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($districtRows, self::CHUNK_SIZE) as $chunk) {
                DB::table('districts')->insert($chunk);
            }
            $this->info("Districts seeded for city {$cityId}: " . count($districtRows));
        }
        $this->info('Districts total: ' . DB::table('districts')->count());

        // 4) Villages (Kelurahan/Desa)
        $this->info('Seeding villages (kelurahan/desa)...');
        $districtIds = DB::table('districts')->pluck('id')->all();
        $seenVillageIds = [];
        $villageProgress = 0;
        foreach ($districtIds as $districtId) {
            $villages = $this->fetchJson(self::BASE . '/villages/' . $districtId . '.json');
            $villageRows = [];
            $duplicateCount = 0;
            foreach ($villages as $v) {
                $villageId = (int) ($v['id'] ?? 0);
                if (!$villageId) {
                    continue;
                }
                if (isset($seenVillageIds[$villageId])) {
                    $duplicateCount++;
                    continue;
                }
                $seenVillageIds[$villageId] = true;
                $villageRows[] = [
                    'id' => $villageId,
                    'district_id' => (int) $districtId,
                    'name' => $v['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($villageRows, self::CHUNK_SIZE) as $chunk) {
                DB::table('villages')->insert($chunk);
            }

            $villageProgress++;
            if ($duplicateCount > 0) {
                $this->info("Duplicate village IDs skipped for district {$districtId}: {$duplicateCount}");
            }

            // avoid too chatty output
            if (($villageProgress % 100) === 0) {
                $this->info("Villages progress... processed districts: {$villageProgress}");
            }
        }
        $this->info('Villages total: ' . DB::table('villages')->count());

        $this->info('Wilayah seeding completed.');
    }

    private function fetchJson(string $url): array
    {
        $cacheDir = storage_path('app/wilayah-cache');
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        $cacheKey = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $url);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';

        if (is_file($cacheFile)) {
            $json = json_decode((string) file_get_contents($cacheFile), true);
            return is_array($json) ? $json : [];
        }

        /** @var \Illuminate\Http\Client\Response $resp */
        $resp = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->retry(5, 500)->get($url);
        if ($resp->failed()) {
            throw new \RuntimeException("Failed requesting: {$url}");
        }

        $json = $resp->json() ?? [];
        if (is_array($json)) {
            @file_put_contents($cacheFile, json_encode($json));
        }

        return $json;
    }

    private function info(string $message): void
    {
        if (app()->runningInConsole()) {
            $this->command?->info($message);
        }
    }
}
