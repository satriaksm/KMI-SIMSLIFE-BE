<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\RoleSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MasterDataSeeder::class,
            CategorySeeder::class,
            RoleSeeder::class,
            JasaSeeder::class,
            PromoSeeder::class,
            UserSeeder::class,
            RoleUserSeeder::class,
            SegmentationSeeder::class,
           // CommunityPostSeeder::class,
            //PostCommentSeeder::class,
            PaguyubanSeeder::class,
            MerchantSeeder::class,
            AddressSeeder::class,
        ]);
    }
}
