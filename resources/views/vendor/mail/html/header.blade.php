@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;color:#FFA30E">
@if (trim($slot) === 'SUMILIR')
  <span style="color:#FFA30E;font-size:24px;font-weight:bold;letter-spacing:1px;">SUMILIR</span>
@else
  {!! $slot !!}
@endif
</a>
</td>
</tr>
