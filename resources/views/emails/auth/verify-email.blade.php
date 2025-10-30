@component('mail::message')
# Verifikasi Email

Halo {{ $userName ?? 'Pengguna' }},

Terima kasih telah mendaftar di SUMILIR. Silakan verifikasi email Anda dengan menekan tombol berikut:

@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
Verifikasi Email
@endcomponent

Jika tombol di atas tidak berfungsi, klik atau salin link berikut:
[{{ $actionUrl }}]({{ $actionUrl }})

Terima kasih,<br>
Tim SUMILIR
@endcomponent
