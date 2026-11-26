@props(['brand'])
{{-- The website's logo at the top of the mail (its name in bold when it has none), linked to its website. --}}
@php($open = $brand['url'] !== '' ? '<a href="'.e($brand['url']).'" target="_blank" rel="noopener" style="display:inline-block;text-decoration:none;">' : '')
@if ($brand['logo'] !== null)
<p style="margin:0 0 20px;">{!! $open !!}<img src="{{ $brand['logo']['url'] }}" width="{{ $brand['logo']['width'] }}" height="{{ $brand['logo']['height'] }}" alt="{{ $brand['name'] }}" style="display:block;border:0;height:{{ $brand['logo']['height'] }}px;width:{{ $brand['logo']['width'] }}px;max-width:100%;">{!! $open !== '' ? '</a>' : '' !!}</p>
@elseif ($brand['name'] !== '')
<p style="margin:0 0 20px;font-size:15px;font-weight:600;color:#212529;">{!! $open !!}<span style="color:#212529;">{{ $brand['name'] }}</span>{!! $open !== '' ? '</a>' : '' !!}</p>
@endif
