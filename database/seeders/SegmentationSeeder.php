<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class SegmentationSeeder extends Seeder
{
    public function run(): void
    {
        $segmentations = [
            ['name' => 'UMKM Toko', 'image_path' => 'segmentations/toko.png'],
            ['name' => 'UMKM Kuliner', 'image_path' => 'segmentations/kuliner.png'],
            ['name' => 'UMKM Jasa', 'image_path' => 'segmentations/jasa.png'],
        ];

        foreach ($segmentations as $segmentation) {
            DB::table('segmentations')->insert($segmentation);
        }
    }
}
