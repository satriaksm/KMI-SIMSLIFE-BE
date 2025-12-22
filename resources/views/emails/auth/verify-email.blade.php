@component('mail::message')
# Verifikasi Email

Halo {{ $userName ?? 'Pengguna' }},

Terima kasih telah mendaftar di SUMILIR. Silakan verifikasi email Anda dengan menekan tombol berikut:

@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
Verifikasi Email
@endcomponent

Jika tombol di atas tidak berfungsi, klik atau salin link berikut:
<a href="{{ $actionUrl }}" style="color:#FFA30E;text-decoration:underline;">{{ $actionUrl }}</a>

Terima kasih,<br>
Tim SUMILIR
@endcomponent
