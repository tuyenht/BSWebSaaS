{{--
    The site search page (Storefront SearchController), between the site's own header and footer.
    $results null: nothing (or one letter) typed — the breadcrumb, the heading, the box and the main
    groups ($quick). Results: the breadcrumb, "Kết quả tìm kiếm", the box with the words searched for,
    the count, the group links (jumps to each group), then each group: its name and count, its rows (the
    whole row is the link: picture or a grey picture box, the title and 1–2 lines of short description
    with the matched words in bold, the group line), and "Xem thêm" for the group's next rows (a link that
    works without a script; storefront.js adds them in place). No results: the box, "Không tìm thấy …",
    the tips ($tips), the main groups and, when the site has a main contact action ($cta), its box.
    Every text is Blade-escaped: a title or description arrives as plain pieces (text, matched) and the
    bold is our own <b>, never the visitor's or the author's markup.
--}}
@php
    $sfGroups = $results['groups'] ?? [];
    $sfNone = $results !== null && $sfGroups === [];
    $sfPhoto = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 5h16v14H4z"/><circle cx="9" cy="10" r="1.6"/><path d="M20 16l-5-5-8 8"/></svg>';
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}"{!! ($dir ?? 'ltr') === 'rtl' ? ' dir="rtl"' : '' !!}>
<head>
@include('theme::partials.head')
@include('theme::partials.embed-code', ['embedSlot' => 'head'])
</head>
<body>
@include('theme::partials.embed-code', ['embedSlot' => 'body_top'])
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="sf-search{{ $sfNone ? ' sf-search--none' : '' }}" id="noi-dung" tabindex="-1">
@if (! $sfNone)
        @include('theme::partials.sf-breadcrumb', ['crumbs' => $crumbs ?? []])
        <h1 class="sf-search__h1">{{ $title }}</h1>
@endif
        <div class="sf-search__form">
            @include('theme::partials.sf-search-field', ['action' => $search, 'id' => 'sf-search-page-q', 'value' => $query])
        </div>
@if ($results === null)
@if (! empty($quick))
        <p class="sf-search__p sf-search__p--g" id="sf-search-groups">{{ __('storefront.search.main_groups') }}</p>
        <ul class="sf-schips" aria-labelledby="sf-search-groups">
@foreach ($quick as $link)
            <li><a class="sf-schip" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
@endforeach
        </ul>
@endif
@elseif ($sfNone)
        <h1 class="sf-search__none" role="status">{{ $title }}</h1>
        <p class="sf-search__p">{{ __('storefront.search.try') }}</p>
        <ul class="sf-search__tips">
@foreach ($tips as $tip)
            <li>{{ $tip }}</li>
@endforeach
        </ul>
@if (! empty($quick))
        <p class="sf-search__p sf-search__p--g" id="sf-search-groups">{{ __('storefront.search.or_groups') }}</p>
        <ul class="sf-schips" aria-labelledby="sf-search-groups">
@foreach ($quick as $link)
            <li><a class="sf-schip" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
@endforeach
        </ul>
@endif
@if (! empty($cta))
        <div class="sf-search__cta">
            <p class="sf-search__cta-t"><b>{{ __('storefront.search.cta_title') }}</b><span>{{ __('storefront.search.cta_text') }}</span></p>
            <a class="sf-search__btn" href="{{ $cta['href'] }}">{{ $cta['label'] }}</a>
        </div>
@endif
@else
        <p class="sf-search__sum" role="status">@if (! $results['continued'])<b>{{ trans_choice('storefront.search.count', $results['total'], ['count' => $results['capped'] ? $results['total'].'+' : $results['total']]) }}</b> @endif{{ __('storefront.search.for', ['q' => $query]) }}</p>
@if (! $results['continued'] && count($sfGroups) > 1)
        <nav aria-label="{{ __('storefront.search.groups') }}">
            <ul class="sf-search__gnav">
@foreach ($sfGroups as $group)
                <li><a class="sf-search__gchip" href="#{{ $group['anchor'] }}">{{ $group['name'] }}<span class="sf-search__cnt">{{ $group['capped'] ? $group['count'].'+' : $group['count'] }}</span></a></li>
@endforeach
            </ul>
        </nav>
@endif
@foreach ($sfGroups as $group)
        <section class="sf-search__grp" id="{{ $group['anchor'] }}" aria-labelledby="{{ $group['anchor'] }}-h" data-search-group>
            <h2 class="sf-search__grp-h" id="{{ $group['anchor'] }}-h">{{ $group['name'] }}<span class="sf-search__cnt">{{ $group['capped'] ? $group['count'].'+' : $group['count'] }}</span></h2>
            <ul class="sf-search__rows" data-search-rows>
@foreach ($group['rows'] as $row)
                <li>
                    <a class="sf-search__rw" href="{{ $row['url'] }}">
@if (! empty($row['image']))
                        <img class="sf-search__img" src="{{ $row['image'] }}"@if (! empty($row['srcset'])) srcset="{{ $row['srcset'] }}" sizes="128px"@endif alt="" width="128" height="96" loading="lazy" decoding="async">
@else
                        <span class="sf-search__img sf-search__img--ph" aria-hidden="true">{!! $sfPhoto !!}</span>
@endif
                        <span class="sf-search__b">
                            <span class="sf-search__t">@foreach ($row['title'] as [$piece, $hit]){!! $hit ? '<b>' : '' !!}{{ $piece }}{!! $hit ? '</b>' : '' !!}@endforeach</span>
@if ($row['text'] !== [])
                            <span class="sf-search__d">@foreach ($row['text'] as [$piece, $hit]){!! $hit ? '<b>' : '' !!}{{ $piece }}{!! $hit ? '</b>' : '' !!}@endforeach</span>
@endif
@if ($row['meta'] !== '')
                            <span class="sf-search__m">{{ $row['meta'] }}</span>
@endif
                        </span>
                    </a>
                </li>
@endforeach
            </ul>
@if ($group['more'] !== null)
            <a class="sf-search__more" href="{{ $group['more']['url'] }}" data-search-more>{{ $group['more']['label'] }}<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg></a>
@endif
        </section>
@endforeach
@endif
    </main>
    @include('theme::partials.embed-code', ['embedSlot' => 'body_end'])
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    @include('theme::partials.embed-code', ['embedSlot' => 'foot'])
</body>
</html>
