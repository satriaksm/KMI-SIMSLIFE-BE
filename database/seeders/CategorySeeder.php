<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Category;


class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['parent_id' => null, 'name' => 'Makanan', 'slug' => 'makanan', 'image_path' => 'categories/makanan.png'],
            ['parent_id' => null, 'name' => 'Minuman', 'slug' => 'minuman', 'image_path' => 'categories/minuman.png'],
            ['parent_id' => null, 'name' => 'Elektronik', 'slug' => 'elektronik', 'image_path' => 'categories/elektronik.png'],
            ['parent_id' => 3, 'name' => 'Smartphone', 'slug' => 'smartphone', 'image_path' => 'categories/smartphone.png'],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->insert($category);
        }
    }
}
