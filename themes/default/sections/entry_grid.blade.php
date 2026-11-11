@if (($variant ?? 'list') === 'zigzag')
{{-- One row per item, the photo alternating left and right; the "01…" number comes from the order (nothing stored).
     The link is the row's button (`link_label`). Rows stack on a phone, photo first. --}}
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-zigzag'])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
@php
    // Ids already taken on the page: the layout's own and the anchors of the page's sections (BlockRenderer).
    $sfZSeen = request()->attributes->get('sf_zigzag_ids', []) + array_fill_keys(\App\Modules\Theme\Components\StyleProps::LAYOUT_IDS, true);
@endphp
@if (count($items) > 0)
<ol class="sf-zigzag__list">
@foreach ($items as $card)
@php
    $sfZUrl = \App\Modules\Shared\Support\SafeLink::href($card['url'] ?? null);
    $sfZHasImage = \App\Modules\Shared\Support\SafeLink::href($card['image'] ?? null) !== null;
    $sfZHasMedia = $sfZHasImage || ($card['icon'] ?? '') !== '';
    $sfZNo = sprintf('%02d', $loop->iteration);
    // The row's anchor is the entry's slug (the last segment of its public path), made unique on the page.
    $sfZId = preg_replace('/[^\p{L}\p{N}_-]/u', '', basename((string) parse_url((string) ($card['url'] ?? ''), PHP_URL_PATH)));
    if ($sfZId !== '' && $sfZId !== null) {
        $sfZBase = $sfZId;
        for ($sfZN = 2; isset($sfZSeen[$sfZId]); $sfZN++) {
            $sfZId = $sfZBase.'-'.$sfZN;
        }
        $sfZSeen[$sfZId] = true;
    }
    $sfZHasId = $sfZId !== '' && $sfZId !== null;
    $sfZClass = 'sf-zigzag__row'.($loop->even ? ' sf-zigzag__row--flip' : '').($sfZHasMedia ? '' : ' sf-zigzag__row--text');
@endphp
@if ($sfZHasId)
<li id="{{ $sfZId }}" class="{{ $sfZClass }}">
@else
<li class="{{ $sfZClass }}">
@endif
@if ($sfZHasMedia)
<div class="sf-zigzag__media">
@if ($sfZHasImage)
@include('theme::partials.sf-img', ['src' => $card['image'], 'srcset' => $card['srcset'] ?? null, 'sizes' => '(min-width:1024px) 560px, (min-width:768px) 45vw, calc(100vw - 2rem)', 'w' => 960, 'h' => 600, 'priority' => 'lazy', 'class' => 'sf-zigzag__img'])
@else
@include('theme::partials.icon', ['name' => $card['icon'], 'stroke' => 1.5, 'wrap' => 'sf-zigzag__glyph'])
@endif
<span class="sf-zigzag__no" aria-hidden="true">{{ $sfZNo }}</span>
</div>
@endif
<div class="sf-zigzag__text">
@if (! $sfZHasMedia)
<span class="sf-zigzag__no sf-zigzag__no--inline" aria-hidden="true">{{ $sfZNo }}</span>
@endif
<h3 class="sf-zigzag__title">{{ $card['title'] ?? '' }}</h3>
@if (($card['excerpt'] ?? '') !== '')
<p class="sf-zigzag__excerpt">{{ $card['excerpt'] }}</p>
@endif
@if ($sfZUrl !== null)
<a class="sf-zigzag__more" href="{{ $sfZUrl }}">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'link_label', __('storefront.view_detail')) }}<span class="sr-only">: {{ $card['title'] ?? '' }}</span></a>
@endif
</div>
</li>
@endforeach
</ol>
@php
    request()->attributes->set('sf_zigzag_ids', $sfZSeen);
@endphp
@else
<p class="sf-empty">{{ __('storefront.no_content') }}</p>
@endif
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => ($display ?? null)['view_all'] ?? true])
@endcomponent
@elseif (($variant ?? 'list') === 'job')
{{-- Job postings: the cards (short facts, badge), a count line of the whole branch (`count_label`, "{n}"),
     filter buttons by sub-category when `tabs` is filter and two or more have posts (sf-tabs.js shows the
     bar and hides the cards of the other groups; without it every card stays), and `empty_text` when the
     branch has no post. --}}
@php
    $sfJobs = $jobs ?? [];
    $sfJobChips = $sfJobs['chips'] ?? [];
    $sfBg = \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG);
    $sfJobEmpty = \App\Modules\Theme\Components\SectionProps::text($props, 'empty_text', __('storefront.no_jobs'));
@endphp
@component('theme::partials.sf-band', ['bg' => $sfBg, 'class' => 'sf-cards sf-cards--job'])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
@if (count($items) > 0)
<div class="sf-jobs" data-sf-filter>
@if (($sfJobs['count'] ?? '') !== '')
<p class="sf-jobs__count">{{ $sfJobs['count'] }}</p>
@endif
@if ($sfJobChips !== [])
<div class="sf-jobs__chips" role="group" aria-label="{{ __('storefront.job_filter') }}" data-sf-chips hidden>
<button type="button" class="sf-jobs__chip" data-sf-pick="all" aria-pressed="true">{{ __('storefront.all') }} ({{ $sfJobs['total'] }})</button>
@foreach ($sfJobChips as $sfChip)
<button type="button" class="sf-jobs__chip" data-sf-pick="{{ $sfChip['key'] }}" aria-pressed="false">{{ $sfChip['name'] }}</button>
@endforeach
</div>
@endif
<ul class="sf-grid sf-grid--2 sf-jobs__list">
@foreach ($items as $card)
<li data-sf-group="{{ $card['group'] }}">@include('theme::partials.sf-card', [
    'card' => $card,
    'kind' => 'job',
    'level' => 'h3',
    'linkLabel' => \App\Modules\Theme\Components\SectionProps::text($props, 'link_label', __('storefront.job_detail')),
])</li>
@endforeach
</ul>
</div>
@else
<div class="sf-empty-box"><p>{{ $sfJobEmpty }}</p></div>
@endif
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => true])
@endcomponent
@elseif (! empty($tabs))
{{-- Tabs by sub-category: every panel is drawn here, on the server, so a search engine reads them all.
     Without JavaScript the panels stack, each under its own heading; sf-tabs.js hides the headings,
     shows the tab bar and switches panels (the ARIA tabs pattern). The cards are the card look's. --}}
@php
    $sfKind = ['icon-cards' => 'icon', 'project-cards' => 'project', 'news-cards' => 'news'][$variant] ?? 'news';
    // Without a display group each look keeps its own default grid (ListDisplay::AUTO's columns); the sizes follow it.
    // News tabs draw 3 across like the board; the plain news branch below stays 4 so old blocks keep their look.
    $sfTabGrid = ['icon' => 'sf-grid--4', 'project' => 'sf-grid--4s', 'news' => 'sf-grid--3s'][$sfKind];
    $sfSizes = $sfKind === 'news'
        ? '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)'
        : '(min-width:1360px) 298px, (min-width:1024px) calc((100vw - 10.5rem)/4), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)';
    $sfBg = \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG);
    $sfLinkLabel = \App\Modules\Theme\Components\SectionProps::text($props, 'link_label', __('storefront.view_detail'));
    // The heading order never skips a level: a block with a heading has its h2, then the panel h3 and the card h4;
    // a block without one starts at the panel h2 and its cards are h3.
    $sfHasHead = \App\Modules\Theme\Components\SectionProps::text($props, 'heading') !== '';
    $sfTitleTag = $sfHasHead ? 'h3' : 'h2';
    $sfCardLevel = $sfHasHead ? 'h4' : 'h3';
@endphp
@component('theme::partials.sf-band', ['bg' => $sfBg, 'class' => 'sf-cards sf-cards--'.$sfKind])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
<div class="sf-tabs" data-sf-tabs>
<div class="sf-tabs__bar" data-label="{{ \App\Modules\Theme\Components\SectionProps::text($props, 'heading', __('storefront.tabs_label')) }}" hidden>
@foreach ($tabs as $sfTab)
<button type="button" class="sf-tabs__tab" data-sf-tab>{{ $sfTab['name'] }}</button>
@endforeach
</div>
@foreach ($tabs as $sfTab)
<div class="sf-tabs__panel" data-sf-tabpanel>
<{{ $sfTitleTag }} class="sf-tabs__title">{{ $sfTab['name'] }}</{{ $sfTitleTag }}>
@php
    $sfDisplay = $sfTab['display'];
    $sfTabSizes = $sfDisplay !== null ? \App\Modules\Theme\Components\ListDisplay::cardSizes($sfDisplay) : $sfSizes;
@endphp
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => $sfTab['name']])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-grid '.$sfTabGrid }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0"@endif>
@foreach ($sfTab['items'] as $card)
<li>@include('theme::partials.sf-card', [
    'card' => $card,
    'kind' => $sfKind,
    'level' => $sfCardLevel,
    'sizes' => $sfTabSizes,
    'linkLabel' => $sfLinkLabel,
    'short' => $sfDisplay !== null,
])</li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
</div>
@endforeach
</div>
{{-- One "view all" after the panels (the board has a single block link), with or without the script. --}}
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => collect($tabs)->contains(fn (array $sfT): bool => $sfT['view_all'])])
@endcomponent
@elseif (in_array($variant ?? 'list', ['icon-cards', 'project-cards', 'news-cards', 'overlay'], true))
{{-- A card look (opt-in through `variant`, SectionProps): the shared band, head, card and
     "view all" partials. The default look below is the original markup, untouched. --}}
@php
    $sfKind = ['icon-cards' => 'icon', 'project-cards' => 'project', 'news-cards' => 'news', 'overlay' => 'product-overlay'][$variant];
    $sfGrid = ['icon' => 'sf-grid--4', 'project' => 'sf-grid--4s', 'news' => 'sf-grid--4s', 'product-overlay' => 'sf-grid--3s'][$sfKind];
    $sfSizes = $sfKind === 'product-overlay'
        ? '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)'
        : '(min-width:1360px) 298px, (min-width:1024px) calc((100vw - 10.5rem)/4), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)';
    if (($display ?? null) !== null) {
        $sfSizes = \App\Modules\Theme\Components\ListDisplay::cardSizes($display);
    }
    $sfBg = \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG);
@endphp
@component('theme::partials.sf-band', ['bg' => $sfBg, 'class' => 'sf-cards sf-cards--'.$sfKind])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
@php($sfDisplay = $display ?? null)
@if (count($items) > 0)
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading', __('storefront.list_rail'))])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-grid '.$sfGrid }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0"@endif>
@foreach ($items as $card)
<li>@include('theme::partials.sf-card', [
    'card' => $card,
    'kind' => $sfKind,
    'level' => 'h3',
    'sizes' => $sfSizes,
    'linkLabel' => \App\Modules\Theme\Components\SectionProps::text($props, 'link_label', __('storefront.view_detail')),
    'short' => $sfDisplay !== null,
])</li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@else
<p class="sf-empty">{{ __('storefront.no_content') }}</p>
@endif
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => $sfDisplay['view_all'] ?? true])
@endcomponent
@else
<section class="entry-grid">
    @if (! empty($props['heading']))
        <h2 class="entry-grid__heading">{{ $props['heading'] }}</h2>
    @endif

    {{-- $items are SERVER-resolved cards (EntryGridComponent::data), not author Page-JSON props —
         hence a data var ($card), like latest_posts' $post. url = a stored route path; image = a
         site meta field. Neither is author-injected Page-JSON, so they are not type:url props. --}}
@php($sfDisplay = $display ?? null)
    @if (count($items) > 0)
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading', __('storefront.list_rail'))])
@endif
        <ul class="{{ $sfDisplay['class'] ?? 'entry-grid__list' }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0"@endif>
            @foreach ($items as $card)
                <li class="entry-grid__card">
                    @if (! empty($card['image']))
                        {{-- Featured image is smart: a path/URL value renders as <img> (CLS-safe via
                             width/height; lazy), otherwise it is a curated glyph key (e.g. a service
                             icon). Same image_url concept across post-type content. --}}
                        @if (\Illuminate\Support\Str::contains((string) $card['image'], ['/', '.', ':']))
                            <img class="entry-grid__image" src="{{ $card['image'] }}" alt="{{ $card['title'] ?? '' }}" width="480" height="320" loading="lazy">
                        @else
                            @include('theme::partials.icon', ['name' => $card['image']])
                        @endif
                    @endif
                    <h3 class="entry-grid__title">
                        @if (! empty($card['url']))
                            <a href="{{ $card['url'] }}">{{ $card['title'] ?? '' }}</a>
                        @else
                            {{ $card['title'] ?? '' }}
                        @endif
                    </h3>
                    @if (! empty($card['sector']))
                        <span class="entry-grid__sector">{{ $card['sector'] }}</span>
                    @endif
                    @if (! empty($card['short_outcome']))
                        <p class="entry-grid__outcome">{{ $card['short_outcome'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
    @else
        <p class="entry-grid__empty">{{ __('storefront.no_content') }}</p>
    @endif
@if ($sfDisplay !== null)
{{-- "View all" only for a block that carries the display group; one saved before it never showed it. --}}
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => $sfDisplay['view_all']])
@endif
</section>
@endif
