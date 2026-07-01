<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuickLocationSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $now = now();

        DB::transaction(function () use ($now) {
            /*
             |--------------------------------------------------------------------------
             | Quick Location Seeder
             |--------------------------------------------------------------------------
             | Seeder ringan untuk testing lokal.
             | Tidak mengambil data dari internet.
             | Tidak truncate addresses agar data alamat lama tidak langsung hilang.
             */

            $provinces = [
                ['id' => 900001, 'name' => 'DKI JAKARTA', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900002, 'name' => 'JAWA BARAT', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900003, 'name' => 'JAWA TENGAH', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900004, 'name' => 'DI YOGYAKARTA', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900005, 'name' => 'JAWA TIMUR', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900006, 'name' => 'BANTEN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900007, 'name' => 'BALI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900008, 'name' => 'SUMATERA UTARA', 'created_at' => $now, 'updated_at' => $now],
            ];

            $cities = [
                ['id' => 90000101, 'province_id' => 900001, 'name' => 'KOTA JAKARTA SELATAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000102, 'province_id' => 900001, 'name' => 'KOTA JAKARTA PUSAT', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000201, 'province_id' => 900002, 'name' => 'KOTA BANDUNG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000202, 'province_id' => 900002, 'name' => 'KABUPATEN BANDUNG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000301, 'province_id' => 900003, 'name' => 'KOTA SEMARANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000302, 'province_id' => 900003, 'name' => 'KOTA SURAKARTA', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000401, 'province_id' => 900004, 'name' => 'KOTA YOGYAKARTA', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000402, 'province_id' => 900004, 'name' => 'KABUPATEN SLEMAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000501, 'province_id' => 900005, 'name' => 'KOTA SURABAYA', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000502, 'province_id' => 900005, 'name' => 'KOTA MALANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000601, 'province_id' => 900006, 'name' => 'KOTA TANGERANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000602, 'province_id' => 900006, 'name' => 'KOTA TANGERANG SELATAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000701, 'province_id' => 900007, 'name' => 'KOTA DENPASAR', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 90000801, 'province_id' => 900008, 'name' => 'KOTA MEDAN', 'created_at' => $now, 'updated_at' => $now],
            ];

            $districts = [
                ['id' => 9000010101, 'city_id' => 90000101, 'name' => 'KEBAYORAN BARU', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000010102, 'city_id' => 90000101, 'name' => 'SETIABUDI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000010201, 'city_id' => 90000102, 'name' => 'MENTENG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000020101, 'city_id' => 90000201, 'name' => 'COBLONG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000020102, 'city_id' => 90000201, 'name' => 'BANDUNG WETAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000020201, 'city_id' => 90000202, 'name' => 'SOREANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000030101, 'city_id' => 90000301, 'name' => 'SEMARANG TENGAH', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000030201, 'city_id' => 90000302, 'name' => 'BANJARSARI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000040101, 'city_id' => 90000401, 'name' => 'GONDOKUSUMAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000040201, 'city_id' => 90000402, 'name' => 'DEPOK', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000050101, 'city_id' => 90000501, 'name' => 'GENTENG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000050201, 'city_id' => 90000502, 'name' => 'KLOJEN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000060101, 'city_id' => 90000601, 'name' => 'TANGERANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000060201, 'city_id' => 90000602, 'name' => 'SERPONG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000070101, 'city_id' => 90000701, 'name' => 'DENPASAR SELATAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 9000080101, 'city_id' => 90000801, 'name' => 'MEDAN KOTA', 'created_at' => $now, 'updated_at' => $now],
            ];

            $villages = [
                ['id' => 900001010101, 'district_id' => 9000010101, 'name' => 'SENAYAN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900001010102, 'district_id' => 9000010101, 'name' => 'GUNUNG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900001010201, 'district_id' => 9000010102, 'name' => 'KARET', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900001020101, 'district_id' => 9000010201, 'name' => 'MENTENG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900002010101, 'district_id' => 9000020101, 'name' => 'DAGO', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900002010102, 'district_id' => 9000020101, 'name' => 'CIPAGANTI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900002010201, 'district_id' => 9000020102, 'name' => 'TAMAN SARI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900002020101, 'district_id' => 9000020201, 'name' => 'SOREANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900003010101, 'district_id' => 9000030101, 'name' => 'SEKAYU', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900003020101, 'district_id' => 9000030201, 'name' => 'BANJARSARI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900004010101, 'district_id' => 9000040101, 'name' => 'BACIRO', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900004020101, 'district_id' => 9000040201, 'name' => 'CATURTUNGGAL', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900005010101, 'district_id' => 9000050101, 'name' => 'EMBONG KALIASIN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900005020101, 'district_id' => 9000050201, 'name' => 'KLOJEN', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900006010101, 'district_id' => 9000060101, 'name' => 'SUKASARI', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900006020101, 'district_id' => 9000060201, 'name' => 'LENGKONG GUDANG', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900007010101, 'district_id' => 9000070101, 'name' => 'SANUR', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 900008010101, 'district_id' => 9000080101, 'name' => 'PUSAT PASAR', 'created_at' => $now, 'updated_at' => $now],
            ];

            DB::table('provinces')->upsert($provinces, ['id'], ['name', 'updated_at']);
            DB::table('cities')->upsert($cities, ['id'], ['province_id', 'name', 'updated_at']);
            DB::table('districts')->upsert($districts, ['id'], ['city_id', 'name', 'updated_at']);
            DB::table('villages')->upsert($villages, ['id'], ['district_id', 'name', 'updated_at']);
        });

        $this->command?->info('Quick location data berhasil di-seed.');
        $this->command?->info('Provinces: ' . DB::table('provinces')->count());
        $this->command?->info('Cities: ' . DB::table('cities')->count());
        $this->command?->info('Districts: ' . DB::table('districts')->count());
        $this->command?->info('Villages: ' . DB::table('villages')->count());
    }
}
