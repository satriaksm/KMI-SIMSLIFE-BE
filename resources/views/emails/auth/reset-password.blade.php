@component('mail::message')
# Reset Password

Halo {{ $userName ?? 'Pengguna' }},

Kami menerima permintaan untuk mengatur ulang password akun Anda.

@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
Atur Ulang Password
@endcomponent

Jika Anda tidak meminta reset password, abaikan email ini.
Jika tombol di atas tidak berfungsi, klik atau salin link berikut:
[{{ $actionUrl }}]({{ $actionUrl }})

Terima kasih,<br>
Tim SUMILIR
@endcomponent
