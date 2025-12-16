<?php

namespace Database\Seeders;

use App\Models\JasaCategory;
use App\Models\JasaSubcategory;
use Illuminate\Database\Seeder;

class JasaCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Kebersihan', 'description' => 'Layanan kebersihan rumah, kantor', 'icon' => 'pi-home'],
            ['name' => 'Kecantikan & Perawatan', 'description' => 'Salon, barbershop, spa', 'icon' => 'pi-star'],
            ['name' => 'Perbaikan & Perawatan Rumah', 'description' => 'Plumbing, listrik, renovasi', 'icon' => 'pi-wrench'],
            ['name' => 'Konsultasi & Jasa Profesional', 'description' => 'Konsultasi bisnis, hukum', 'icon' => 'pi-briefcase'],
            ['name' => 'Fotografi & Videografi', 'description' => 'Fotografi & videografi', 'icon' => 'pi-camera'],
            ['name' => 'Pendidikan & Kursus', 'description' => 'Kursus bahasa, musik, programming', 'icon' => 'pi-book'],
            ['name' => 'Transportasi & Logistik', 'description' => 'Jasa antar, pindahan', 'icon' => 'pi-truck'],
            ['name' => 'Teknologi & IT', 'description' => 'Reparasi gadget, maintenance IT', 'icon' => 'pi-laptop'],
        ];

        foreach ($categories as $categoryData) {
            $category = JasaCategory::create($categoryData);

            $subcategories = match ($category->name) {
                'Kebersihan' => ['Kebersihan Rumah', 'Kebersihan Kantor', 'Kebersihan Kendaraan'],
                'Kecantikan & Perawatan' => ['Barbershop / Salon Pria', 'Salon Wanita', 'Spa & Massage'],
                'Perbaikan & Perawatan Rumah' => ['Perbaikan Listrik', 'Perbaikan Plumbing', 'Renovasi'],
                'Konsultasi & Jasa Profesional' => ['Konsultasi Bisnis', 'Konsultasi Hukum', 'Akuntan'],
                'Fotografi & Videografi' => ['Fotografi Pernikahan', 'Fotografi Acara', 'Videografi'],
                'Pendidikan & Kursus' => ['Bahasa Inggris', 'Musik & Seni', 'Programming'],
                'Transportasi & Logistik' => ['Jasa Antar Barang', 'Pindahan', 'Pengiriman'],
                'Teknologi & IT' => ['Reparasi Smartphone', 'Reparasi Laptop', 'Instalasi Software'],
                default => [],
            };

            foreach ($subcategories as $subName) {
                JasaSubcategory::create([
                    'jasa_category_id' => $category->id,
                    'name' => $subName,
                    'is_active' => true,
                ]);
            }
        }
    }
}

