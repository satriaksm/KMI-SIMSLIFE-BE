@component('mail::message')
# Pendaftaran UMKM Ditolak

Halo {{ $userName ?? 'Pengguna' }},

Terima kasih atas ketertarikan Anda untuk bergabung dan mengembangkan usaha UMKM Anda bersama SUMILIR.

Setelah meninjau pendaftaran UMKM Anda dengan nama **{{ $merchantName }}**, mohon maaf saat ini kami belum dapat menyetujui pendaftaran tersebut.

Hal ini mungkin disebabkan oleh informasi yang diajukan belum lengkap atau tidak sesuai dengan standar panduan pendaftaran kami. Anda dapat mencoba mendaftar kembali dengan memastikan seluruh data diisi dengan valid dan sejelas-jelasnya.

Jika ada pertanyaan lebih lanjut, silakan menghubungi layanan dukungan kami.

Terima kasih,<br>
Tim SUMILIR
@endcomponent
