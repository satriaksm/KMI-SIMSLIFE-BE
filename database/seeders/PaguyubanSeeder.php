<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaguyubanSeeder extends Seeder
{
    public function run()
    {
        DB::table('paguyubans')->insert([
            ['name' => 'Paguyuban Kuliner'],
            ['name' => 'Paguyuban Toko'],
            ['name' => 'Paguyuban Jasa'],
        ]);
    }
}
