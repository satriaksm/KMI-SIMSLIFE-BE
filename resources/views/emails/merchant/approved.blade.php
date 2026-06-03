@component('mail::message')
# Pendaftaran UMKM Disetujui

Halo {{ $userName ?? 'Pengguna' }},

Kabar gembira! Pendaftaran usaha UMKM Anda dengan nama **{{ $merchantName }}** telah resmi disetujui oleh tim kami.

Mulai sekarang, Anda dapat mengakses dashboard pengelolaan di aplikasi dan mulai mempublikasikan beragam produk atau jasa Anda kepada pelanggan SUMILIR. Jangan lupa untuk senantiasa melengkapi profil usaha Anda agar terlihat lebih menarik!

@component('mail::button', ['url' => env('FRONTEND_URL', 'http://localhost:5173') . '/merchant-center/' . $merchant->slug . '/dashboard', 'color' => 'primary'])
Buka Dashboard UMKM
@endcomponent

Terima kasih telah bergabung menjadi bagian dari SUMILIR.

Terima kasih,<br>
Tim SUMILIR
@endcomponent
