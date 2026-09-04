# SUMILIR - Backend (API)

## Deskripsi Singkat 📝

Repository ini berisi kode sumber untuk sisi backend (API) dari platform **SUMILIR** (Sistem Informasi Manajemen Layanan Inovasi & Fleksibilitas Ekonomi UMKM). Platform ini adalah aplikasi *hyperlocal* berbasis kelurahan yang mengintegrasikan Marketplace (produk & jasa), Forum Komunitas, dan Peta Interaktif untuk memberdayakan UMKM lokal.

Backend ini dibangun menggunakan **Laravel 12** dan berfungsi sebagai penyedia data serta logika bisnis untuk aplikasi Frontend (Vue 3).

---

## Model Transaksi & Pembayaran 💳

> [!NOTE]
> **Aplikasi ini TIDAK menggunakan Payment Gateway pihak ketiga (seperti Midtrans/Xendit).**
>
> Mengingat karakteristik UMKM lokal dan model *hyperlocal*, transaksi dirancang secara langsung, cepat, dan tanpa potongan biaya transaksi (*zero gateway fee*):
> 1. **Cash on Delivery (COD) / Bayar di Tempat:** Pembeli membayar tunai saat pesanan diantarkan oleh kurir toko/penjual.
> 2. **Ambil di Toko (Self-Pickup):** Pembeli mengambil langsung ke toko dan menyelesaikan pembayaran saat pengambilan.
> 3. **Transfer Manual / Koordinasi WhatsApp:** Pembeli dan merchant dapat berkoordinasi langsung mengenai bukti transfer atau detail pesanan via tautan chat WhatsApp otomatis dan chat internal.

---

## Fitur Utama yang Dikelola Backend ✨

* **Manajemen Pengguna & UMKM:**
  * Autentikasi berbasis token/cookie (Laravel Sanctum).
  * Manajemen profil, alamat pengiriman, dan verifikasi merchant.
  * Role & Permission: Customer, UMKM Owner (Merchant), dan Administrator.

* **Katalog Produk & Jasa:**
  * Manajemen katalog produk dengan varian harga dan add-on.
  * Manajemen katalog jasa dengan sistem *direct booking*, portofolio, dan konsultasi.
  * Manajemen stok produk dan ketersediaan slot jasa.

* **Alur Transaksi & Pemesanan (Hyperlocal E-Commerce):**
  * Keranjang belanja terisolasi per-merchant (seperti alur pemesanan makanan).
  * Sistem diskon dan voucher (Voucher Toko & Voucher Event).
  * Manajemen status pesanan (Menunggu Konfirmasi, Selesai, Dibatalkan).

* **Peta Interaktif (Hyperlocal Map):**
  * API koordinat lokasi UMKM dan zona pengiriman di wilayah kelurahan.

* **Komunitas & Ulasan:**
  * Forum diskusi warga/komunitas (posting, komentar, interaksi).
  * Moderasi konten dan pelaporan ulasan/postingan bermasalah.

* **Laporan & Dashboard:**
  * Dashboard statistik penjualan untuk UMKM Owner.
  * Dashboard analitik menyeluruh dan manajemen platform untuk Admin.
  * Ekspor laporan pesanan (PDF via DomPDF dan Excel via Maatwebsite).

---

## Tech Stack Utama 💻

* **Framework:** Laravel 12
* **Bahasa:** PHP 8.2+
* **Database:** MySQL 8.0+
* **Real-time WebSockets:** Laravel Reverb
* **Autentikasi:** Laravel Sanctum
* **Caching & Queue:** Database Driver / Redis
* **Dokumen & Ekspor:** Barryvdh Laravel-DomPDF & Maatwebsite Excel
* **Web Server:** Nginx (atau Apache / PHP Built-in Server)
* **Manajemen Paket:** Composer

---

## Panduan Instalasi 🚀

1. **Clone repository:**
   ```bash
   git clone https://github.com/satriaksm/KMI-SIMSLIFE-BE.git
   cd KMI-SIMSLIFE-BE
   ```

2. **Install dependensi PHP:**
   ```bash
   composer install
   ```

3. **Salin file konfigurasi environment:**
   ```bash
   cp .env.example .env
   ```

4. **Konfigurasi `.env`:**
   Sesuaikan koneksi database dan URL aplikasi:
   ```env
   APP_URL=http://localhost:8000
   FRONTEND_URL=http://localhost:5173

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=sumilir_ta
   DB_USERNAME=root
   DB_PASSWORD=

   BROADCAST_CONNECTION=reverb
   REVERB_APP_ID=local
   REVERB_APP_KEY=local
   REVERB_APP_SECRET=local
   REVERB_HOST=127.0.0.1
   REVERB_PORT=8081
   REVERB_SCHEME=http
   ```

5. **Generate Application Key:**
   ```bash
   php artisan key:generate
   ```

6. **Jalankan Migrasi & Seeder Database:**
   ```bash
   php artisan migrate --seed
   ```

7. **Hubungkan Storage Symlink:**
   ```bash
   php artisan storage:link
   ```

---

## Menjalankan Aplikasi (Development) ▶️

Untuk menjalankan seluruh layanan di lingkungan lokal, buka 3 tab terminal:

1. **Terminal 1 - Server API Laravel:**
   ```bash
   php artisan serve
   ```
   *(Akan berjalan di `http://localhost:8000`)*

2. **Terminal 2 - Server WebSocket (Reverb):**
   ```bash
   php artisan reverb:start --port=8081
   ```
   *(Menangani pesan chat dan notifikasi real-time di port 8081)*

3. **Terminal 3 - Queue Worker (Opsional):**
   ```bash
   php artisan queue:work
   ```

---

## Menjalankan Pengujian (Testing) ✅

```bash
php artisan test
```
