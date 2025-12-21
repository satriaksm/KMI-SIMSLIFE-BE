# SUMILIR - Backend (API)

## Deskripsi Singkat 📝

Repository ini berisi kode sumber untuk sisi backend (API) dari platform **SUMILIR**. Platform ini adalah aplikasi hyperlocal berbasis kelurahan yang menggabungkan fitur E-commerce (produk & jasa), Komunitas, dan Peta Interaktif untuk memberdayakan UMKM lokal. Backend ini dibangun menggunakan **Laravel** dan berfungsi sebagai penyedia data dan logika bisnis untuk aplikasi Frontend (Vue.js).

## Fitur Utama yang Dikelola Backend ✨

* Manajemen Pengguna & UMKM (Registrasi, Profil, Alamat, Verifikasi)
* Manajemen Peran & Hak Akses (Customer, UMKM Owner, Admin)
* Manajemen Katalog Produk (termasuk Varian & Add-on)
* Manajemen Katalog Jasa (Direct Booking & Konsultasi)
* Manajemen Inventaris (Stok Produk) & Ketersediaan (Jasa & Produk Non-Stok)
* Alur Transaksi E-commerce (Keranjang, Checkout, Order - ala Shopee Food)
* Alur Transaksi POS (termasuk Tahan Transaksi)
* Manajemen Pengiriman (Pickup & Seller Delivery, Kalkulasi Ongkir Berbasis Zona)
* Sistem Voucher (UMKM & Event)
* Sistem Event Promosi
* Forum Komunitas (Posting & Komentar)
* Chat Privat (Customer - UMKM) & Konsultasi Jasa
* Sistem Review & Rating
* Moderasi Konten (Pelaporan)
* API untuk data Peta (Lokasi UMKM)
* Dashboard Admin

## Tech Stack Utama 💻

* **Framework:** Laravel 12
* **Bahasa:** PHP
* **Database:** MySQL [Versi]
* **Caching/Queues:** Redis [Versi]
* **Autentikasi API:** Laravel Sanctum
* **Real-time:** Laravel Websockets (Reverb)
* **Web Server:** Nginx (Direkomendasikan)
* **Manajemen Paket:** Composer

## Instalasi 🚀

1.  **Clone repository:**
    ```bash
    git clone https://github.com/satriaksm/KMI-SIMSLIFE-BE.git
    cd KMI-SIMSLIFE-BE
    ```
2.  **Install dependensi Composer:**
    ```bash
    composer install
    ```
3.  **Salin file environment:**
    ```bash
    cp .env.example .env
    ```
4.  **Konfigurasi file `.env`:**
    * Sesuaikan detail koneksi database (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
    * Konfigurasi koneksi Redis (`REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`).
    * Konfigurasi URL aplikasi (`APP_URL`).
    * Konfigurasi Laravel Websockets (`PUSHER_APP_ID`, `PUSHER_APP_KEY`, etc.).
5.  **Generate application key:**
    ```bash
    php artisan key:generate
    ```
6.  **Jalankan migrasi database (dan seeder jika ada):**
    ```bash
    php artisan migrate --seed
    ```
7.  **(Jika menggunakan Laravel Websockets) Install dependensi NPM:**
    ```bash
    npm install
    # Mungkin perlu build aset jika ada
    # npm run build 
    ```
8.  **Setup storage link:**
    ```bash
    php artisan storage:link
    ```
9.  **Konfigurasi web server** Anda (Nginx/Apache) agar menunjuk ke direktori `public`.

## Menjalankan Aplikasi (Development) ▶️

1.  **Jalankan server development Laravel:**
    ```bash
    php artisan serve
    ```
2.  **Jalankan queue worker:**
    ```bash
    php artisan queue:work
    ```
3.  **(Jika menggunakan Laravel Websockets) Jalankan server WebSocket:**
    ```bash
    php artisan websockets:serve
    ```

Aplikasi backend sekarang berjalan dan siap menerima request API di `APP_URL` yang Anda tentukan (default: `http://localhost:8000`).

## Menjalankan Test ✅

```bash
php artisan test
