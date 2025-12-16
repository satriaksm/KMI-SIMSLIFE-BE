@component('mail::message')
# Reset Kata Sandi

Halo {{ $userName ?? 'Pengguna' }},

Kami menerima permintaan untuk mengatur ulang kata sandi akun Anda.

@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
Atur Ulang Kata Sandi
@endcomponent

Jika Anda tidak meminta reset kata sandi, abaikan email ini.
Jika tombol di atas tidak berfungsi, klik atau salin link berikut:
<a href="{{ $actionUrl }}" style="color:#FFA30E;text-decoration:underline;">{{ $actionUrl }}</a>

Terima kasih,<br>
Tim SUMILIR
@endcomponent
