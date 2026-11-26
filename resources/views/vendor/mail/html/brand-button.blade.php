@props(['url', 'color', 'text'])
{{-- The frame's button in the website's colour, its words in the ink that reads on it (MailBrand::textOn). --}}
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="border-radius:4px;background-color:{{ $color }};">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="background-color:{{ $color }};border-color:{{ $color }};border-bottom:8px solid {{ $color }};border-left:18px solid {{ $color }};border-right:18px solid {{ $color }};border-top:8px solid {{ $color }};color:{{ $text }};">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
