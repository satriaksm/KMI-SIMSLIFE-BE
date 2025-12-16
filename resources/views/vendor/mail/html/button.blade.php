@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}"
   class="button button-{{ $color ?? 'primary' }}"
   target="_blank" rel="noopener"
   @if(($color ?? 'primary') === 'primary')
     style="background-color:#ffa30e !important;border-radius:4px !important;border:1px solid #ffa30e !important;color:#ffffff !important;text-decoration:none;display:inline-block;padding:8px 18px;"
   @endif
>
    {{ $slot }}
</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
