{{--
    Rich storefront header (site setting `storefront.header`, normalized into $header): an optional
    top bar (contact items, language switch) that scrolls away, then the main bar
    — logo, the desktop menu at the bar's right end from lg (from xl when it is too wide for a
    laptop: sf-header--menu-xl), the menu button and its panel below that (the phone menu when the
    site set one, else the main menu) — which stays at the
    top. One <header> landmark holds both; it sticks with the top bar's height scrolled off, so no
    script runs on scroll and nothing shifts. Every value is Blade-escaped; links come validated.
    The text size lives in the phone menu (drawer) as three joined radio cells; only a header without a drawer keeps
    the button in the top bar (below lg). While the phone bottom bar carries a call, the top bar's phone items step aside
    under 768px, and a width band where the top bar would hold nothing draws no top bar at all.
--}}
@php
    // every public language of the site (PageChrome: StorefrontChrome::languages), none for a one-language site
    $sfLanguages = $languages ?? [];
    // The phone menu shows three levels at most: deeper rows follow their level-3 row as level-3 rows (StorefrontChrome::drawerCapDepth).
    $drawerMenu = \App\Modules\Theme\Chrome\StorefrontChrome::drawerCapDepth(! empty($mobileMenu) ? $mobileMenu : ($menu ?? []));
    $sfFsTop = $header['text_size'] && empty($drawerMenu);
    // The phone bottom bar (under 768px) and whether it carries the call: then the header's own copies
    // of the call (the corner icon, the top bar's phone items) step aside there.
    $sfBar = ! empty($floatingContact) && $floatingContact['layout'] !== 'vertical';
    $sfBarCells = $sfBar ? $floatingContact['bar'] : [];
    $sfBarCalls = in_array('phone', array_column($sfBarCells, 'key'), true);
    // Which width bands the top bar has something to show in: xs <640, sm 640–767, md 768–1023, lg ≥1024.
    $sfBands = ['xs' => ['xs'], 'sm' => ['xs', 'sm'], 'md' => ['xs', 'sm', 'md'], 'lg' => ['xs', 'sm', 'md', 'lg']];
    $sfBelow = ['xs' => ['sm', 'md', 'lg'], 'sm' => ['md', 'lg'], 'md' => ['lg'], 'lg' => []];
    $sfTopOff = [];
    foreach ($sfBands as $band => $froms) {
        $shows = $sfLanguages !== [] || ($sfFsTop && $band !== 'lg');
        foreach ($header['top_bar'] as $item) {
            $stepsAside = $sfBarCalls && $item['kind'] === 'phone' && in_array($band, ['xs', 'sm'], true);
            $shows = $shows || (! $stepsAside && (in_array($item['from'], $froms, true) || in_array($item['below'], $sfBelow[$band], true)));
        }
        if (! $shows) {
            $sfTopOff[] = $band;
        }
    }
    $sfTopBar = count($sfTopOff) < 4;
    $sfTopOffClass = $sfTopBar ? implode('', array_map(static fn (string $band): string => ' sf-header--top-off-'.$band, $sfTopOff)) : '';
    // a menu too wide for a 1024px laptop keeps the menu button up to 1280px (StorefrontChrome::menuNeedsXl)
    // (the search button at the end of the menu row counts against that room)
    $sfSearch = ! empty($search) ? $search : null;
    $sfMenuXl = ! empty($menu) && \App\Modules\Theme\Chrome\StorefrontChrome::menuNeedsXl($menu, $sfSearch !== null);
    $sfSizes = ['m' => __('storefront.text_size_m'), 'l' => __('storefront.text_size_l'), 'xl' => __('storefront.text_size_xl')];
@endphp
<header class="sf-header{{ $header['sticky'] ? ' sf-header--sticky' : '' }}{{ $sfTopBar ? ' sf-header--topbar' : '' }}{{ $sfMenuXl ? ' sf-header--menu-xl' : '' }}{{ $sfTopOffClass }}">
    @if ($sfTopBar)
        <div class="sf-topbar">
            <div class="sf-container sf-topbar__inner">
                <ul class="sf-topbar__items">
                    @foreach ($header['top_bar'] as $item)
                        <li class="sf-topbar__item sf-from-{{ $item['from'] }}{{ $item['below'] !== null ? ' sf-below-'.$item['below'] : '' }}{{ $sfBarCalls && $item['kind'] === 'phone' ? ' sf-topbar__item--bar' : '' }}">
                            @if ($item['href'] !== null)
                                <a class="sf-topbar__link" href="{{ $item['href'] }}">@include('theme::partials.sf-icon', ['name' => $item['kind'], 'size' => 16])<span>{{ $item['text'] }}</span></a>
                            @else
                                @include('theme::partials.sf-icon', ['name' => $item['kind'], 'size' => 16])<span>{{ $item['text'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="sf-topbar__tools">
                    @if ($sfLanguages !== [])
                        <nav class="sf-lang" aria-label="{{ __('storefront.language') }}">
                            <ul class="sf-lang__list">
                                @foreach ($sfLanguages as $language)
                                    <li>
                                        <a class="sf-lang__link" href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" @if ($language['current']) aria-current="true" @endif>{{ strtoupper($language['locale']) }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                    @if ($sfFsTop)
                        <button type="button" class="sf-fs" data-fs-toggle
                            aria-label="{{ __('storefront.text_size_state', ['size' => $sfSizes['m']]) }}"
                            @foreach ($sfSizes as $step => $label) data-label-{{ $step }}="{{ $label }}" data-aria-{{ $step }}="{{ __('storefront.text_size_state', ['size' => $label]) }}" @endforeach
                        >@include('theme::partials.sf-icon', ['name' => 'text-size', 'size' => 16])<span>{{ __('storefront.text_size') }}</span><span class="sf-fs__value">: <span data-fs-value>{{ $sfSizes['m'] }}</span></span></button>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @php
        // The phone header's right corner. While the phone bottom bar is on, the call is hidden only where
        // that bar shows (under 768px; it carries the call itself), so a tablet keeps it. Where the call
        // steps aside (or the corner is set to search, or to the cart the site does not have yet), the
        // magnifier takes the corner: cart, then search, then nothing. A site with no bottom bar keeps the
        // call in the corner, so the magnifier stands before it there, on phones too (the menu has no search).
        $sfCallHref = \App\Modules\Shared\Support\SafeLink::tel($brand['phone'] ?? null);
        $sfCornerSetting = $header['mobile_corner'] ?? 'call';
        $sfCorner = $sfCornerSetting === 'call' && $sfCallHref !== null;
        $sfCornerSearch = $sfSearch !== null && in_array($sfCornerSetting, ['call', 'search', 'cart'], true);
        // both buttons in the corner on every width up to the menu button's last one
        $sfCornerPair = $sfCornerSearch && $sfCorner && ! $sfBar;
    @endphp
    <div class="sf-bar">
        <div class="sf-container sf-bar__inner{{ ! empty($drawerMenu) ? ' sf-bar__inner--drawer' : '' }}{{ ! empty($drawerMenu) && $sfCornerPair ? ' sf-bar__inner--pair' : '' }}">
            @if (! empty($drawerMenu))
                <button type="button" class="sf-burger" aria-expanded="false" aria-controls="mobile-menu" data-menu-toggle
                    aria-label="{{ __('storefront.menu_open') }}" data-label-open="{{ __('storefront.menu_open') }}" data-label-close="{{ __('storefront.menu_close') }}">
                    <span class="sf-burger__open">@include('theme::partials.sf-icon', ['name' => 'menu', 'size' => 24])</span><span class="sf-burger__close">@include('theme::partials.sf-icon', ['name' => 'close', 'size' => 24])</span><span class="sf-burger__word">{{ __('storefront.menu_word') }}</span>
                </button>
            @endif
            @if (! empty($brand['logo']))
                {{-- The logo loads at once (no lazy) but at low priority: on the media host it would otherwise take the
                     connection ahead of the page's main photo, which is what the visitor waits for (measured on a phone profile). --}}
                <a class="sf-bar__brand" href="{{ $homeUrl ?? '/' }}">
                    <img class="sf-bar__logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] ?? __('storefront.home') }}" width="156" height="40" fetchpriority="low">
                </a>
            @endif
            @if (! empty($menu))
                <nav class="sf-nav sf-nav--desk" aria-label="{{ __('storefront.nav_main') }}">
                    @include('theme::partials.nav-disclosure', ['items' => $menu, 'depth' => 0, 'idPrefix' => 'dsm', 'drawer' => false])
                    @if ($sfSearch !== null)
                        <span class="sf-sdiv" aria-hidden="true"></span>
                        @include('theme::partials.sf-search-button', ['href' => $sfSearch, 'place' => 'desk'])
                    @endif
                </nav>
            @elseif ($sfSearch !== null)
                @include('theme::partials.sf-search-button', ['href' => $sfSearch, 'place' => 'solo'])
            @endif
            @if (! empty($drawerMenu))
                <span class="sf-bar__corner">@includeWhen($sfCornerSearch, 'theme::partials.sf-search-button', ['href' => $sfSearch, 'place' => 'corner'])@if ($sfCorner)<a class="sf-bar__call{{ $sfBar ? ' sf-bar__call--hasbar' : '' }}" href="{{ $sfCallHref }}" aria-label="{{ __('storefront.call_number', ['phone' => $brand['phone']]) }}">@include('theme::partials.sf-icon', ['name' => 'phone', 'size' => 24])</a>@endif</span>
            @endif
        </div>
    </div>
    @if ($sfSearch !== null)
        {{-- The search panel: opens under the header (a script places it), as wide as the header's content,
             over the dimmed page; the header itself stays bright. Esc, the ✕ or a click outside closes it and
             focus goes back to the button. Without a script the buttons are links to the search page. --}}
        <div class="sf-spanel" id="sf-search-panel" role="dialog" aria-label="{{ __('storefront.search.open') }}" hidden data-search-panel>
            <div class="sf-spanel__dim"></div>
            <div class="sf-container sf-spanel__wrap">
                <div class="sf-spanel__box">
                    @include('theme::partials.sf-search-field', ['action' => $sfSearch, 'id' => 'sf-search-q', 'close' => true])
                    @php
                        $sfQuick = \App\Modules\Storefront\Support\NotFoundPage::suggestions($menu ?? [], $homeUrl ?? '/', 6);
                    @endphp
                    @if ($sfQuick !== [])
                        <p class="sf-spanel__label" id="sf-search-quick" data-search-quick>{{ __('storefront.search.quick') }}</p>
                        <ul class="sf-schips" aria-labelledby="sf-search-quick" data-search-quick>
                            @foreach ($sfQuick as $link)
                                <li><a class="sf-schip" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @if (! empty($drawerMenu))
        @php
            // The phone menu's tone (light by default; dark when set to dark or to follow the header bar) and its colours,
            // derived from the site's seeds (DrawerTones); the stylesheet's fallbacks apply to seeds that are not hex.
            $sfDrawerDark = \App\Modules\Theme\Chrome\StorefrontChrome::drawerDark($header);
            $sfDrawerTones = \App\Modules\Theme\Theming\DrawerTones::css($tokens['surface'] ?? '', $tokens['ink'] ?? '', $tokens['primary'] ?? '', $sfDrawerDark, ($header['drawer_accent'] ?? 'neutral') === 'brand');
        @endphp
        {{-- The phone menu: a dialog drawer (focus moves in on open and back to the button on close, Esc,
             the dimmed area and the ✕ close it). No title word in it: the page's own words are the menu. --}}
        <div id="mobile-menu" class="sf-drawer{{ $sfDrawerDark ? ' sf-drawer--dark' : '' }}" role="dialog" aria-modal="true" aria-label="{{ __('storefront.menu_word') }}" hidden @if ($sfDrawerTones !== null)style="{{ $sfDrawerTones }}"@endif>
            <div class="sf-drawer__scrim" data-menu-close></div>
            <button type="button" class="sf-drawer__close" data-menu-close aria-label="{{ __('storefront.menu_close') }}">@include('theme::partials.sf-icon', ['name' => 'close', 'size' => 24])</button>
            <div class="sf-drawer__panel">
                @php
                    // "Sản phẩm" tab: only with a product list and a category under it (PageChrome → DrawerProducts).
                    $sfTabs = ! empty($drawerProducts);
                    $sfName = $brand['name'] ?? '';
                    $sfSocials = \App\Modules\Theme\Chrome\StorefrontChrome::socials($brand['social_links'] ?? null);
                    // The call and Zalo buttons never repeat the floating contact (phone bottom bar or round
                    // buttons): each shows only when that contact does not carry the same channel, so a site that
                    // turned the floating contact off, or left the call out of it, still offers the call here.
                    $sfFcKeys = [];
                    foreach (($floatingContact['buttons'] ?? []) as $sfChannel) {
                        if (empty($sfChannel['pending'])) {
                            $sfFcKeys[$sfChannel['key']] = true;
                        }
                    }
                    // The language control by count (see the footer): the current language names the row that opens the list.
                    $sfLangSub = count($sfLanguages) >= 8;
                    $sfLangCurrent = $sfLanguages === [] ? null : (collect($sfLanguages)->firstWhere('current', true) ?? $sfLanguages[0]);
                    $sfOwnCall = ! isset($sfFcKeys['phone']) && $sfCallHref !== null;
                    $sfOwnZalo = ! isset($sfFcKeys['zalo']);
                    $sfZaloSocial = null;
                    foreach ($sfSocials as $sfS) {
                        if ($sfS['network'] === 'zalo') {
                            $sfZaloSocial = $sfS;
                        }
                    }
                    $sfZaloHref = $sfOwnZalo ? ($sfZaloSocial['url'] ?? null) : null;
                    // A Zalo circle only for an Official Account or page link: a phone-number Zalo is a chat
                    // button's business, and where the Zalo button shows the circle would repeat it.
                    $sfSocials = array_values(array_filter($sfSocials, static fn (array $s): bool => $s['network'] !== 'zalo' || (! $s['phone'] && $sfZaloHref === null)));
                @endphp
                @if ($sfTabs)
                    <div class="sf-drawer__tabs" role="tablist" aria-label="{{ __('storefront.drawer_tabs_label') }}" data-drawer-tabs>
                        <button type="button" class="sf-drawer__tab" role="tab" id="msm-tab-menu" aria-controls="msm-pane-menu" aria-selected="true">{{ __('storefront.tab_menu') }}</button>
                        <button type="button" class="sf-drawer__tab" role="tab" id="msm-tab-products" aria-controls="msm-pane-products" aria-selected="false" tabindex="-1">{{ __('storefront.tab_products') }}</button>
                    </div>
                @endif
                {{-- Under the tab row (on a screen tall enough) only this part scrolls; the footer is shared by both tabs. --}}
                <div class="sf-drawer__scroll">
                    <div class="sf-drawer__pane"@if ($sfTabs) role="tabpanel" id="msm-pane-menu" aria-labelledby="msm-tab-menu"@endif>
                        <nav class="sf-nav sf-nav--mob{{ \App\Modules\Theme\Chrome\StorefrontChrome::drawerHasIcons($drawerMenu) ? ' sf-nav--icons' : '' }}" aria-label="{{ __('storefront.nav_main') }}">
                            @include('theme::partials.nav-disclosure', ['items' => $drawerMenu, 'depth' => 0, 'idPrefix' => 'msm', 'drawer' => true])
                        </nav>
                    </div>
                    @if ($sfTabs)
                        <div class="sf-drawer__pane" role="tabpanel" id="msm-pane-products" aria-labelledby="msm-tab-products" hidden>
                            <p class="sf-drawer__cap" id="msm-products-cap">{{ __('storefront.product_groups') }}</p>
                            <nav class="sf-nav sf-nav--mob" aria-labelledby="msm-products-cap">
                                @include('theme::partials.drawer-tree', ['items' => \App\Modules\Theme\Chrome\StorefrontChrome::drawerCapDepth($drawerProducts['items']), 'depth' => 0, 'idPrefix' => 'psm'])
                            </nav>
                            <a class="sf-drawer__all" href="{{ $drawerProducts['url'] }}"><span>{{ __('storefront.products_all') }}</span>@include('theme::partials.sf-icon', ['name' => 'arrow-right', 'size' => 18])</a>
                        </div>
                    @endif
                    @if ($sfLanguages !== [] || $header['text_size'] || $sfOwnCall || $sfZaloHref !== null || $sfSocials !== [])
                        <div class="sf-drawer__foot">
                            @if ($sfLanguages !== [])
                                {{-- By count: two or three languages are one segmented control; four to seven a "Ngôn ngữ · <name>"
                                     row that opens the list below it; eight or more the same row opening a screen of its own with a
                                     search field (after the scrolling part of the panel). Without a script the list is simply shown. --}}
                                @if (count($sfLanguages) <= 3)
                                    <div class="sf-drawer__blk">
                                        <div class="sf-drawer__fh">@include('theme::partials.sf-icon', ['name' => 'globe', 'size' => 20])<span aria-hidden="true">{{ __('storefront.language') }}</span></div>
                                        <nav class="sf-drawer__langs" aria-label="{{ __('storefront.language') }}">
                                            <ul class="sf-seg">
                                                @foreach ($sfLanguages as $language)
                                                    <li><a class="sf-seg__s" href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" dir="{{ $language['dir'] }}"@if ($language['current']) aria-current="true"@endif>{{ $language['name'] }}@if ($language['fallback']) <small class="sf-drawer__hint">{{ __('storefront.lang_home') }}</small>@endif</a></li>
                                                @endforeach
                                            </ul>
                                        </nav>
                                    </div>
                                @else
                                    <div class="sf-drawer__lang" data-lang-inline>
                                        <button type="button" class="sf-lang-h" data-lang-head aria-expanded="false" aria-controls="msm-lang-list">@include('theme::partials.sf-icon', ['name' => 'globe', 'size' => 20])<span class="sf-lang-h__t">{{ __('storefront.language') }} · <span lang="{{ $sfLangCurrent['locale'] }}" dir="{{ $sfLangCurrent['dir'] }}">{{ $sfLangCurrent['name'] }}</span></span><span class="sf-lang-h__chev" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</span></button>
                                        @if (! $sfLangSub)
                                            <nav class="sf-lang-list" id="msm-lang-list" aria-label="{{ __('storefront.language') }}">
                                                <ul class="sf-lang-l">
                                                    @foreach ($sfLanguages as $language)
                                                        @include('theme::partials.drawer-lang-row', ['language' => $language])
                                                    @endforeach
                                                </ul>
                                            </nav>
                                        @endif
                                    </div>
                                @endif
                            @endif
                            @if ($header['text_size'])
                                {{-- Three boxed "A" in fixed sizes beside the label (under it when there is no room); each says its size's
                                     name to a screen reader and in a tooltip. A script sets the page's size (the group is hidden without one). --}}
                                <div class="sf-drawer__blk sf-drawer__row sf-drawer__fs" data-fs-set>
                                    <div class="sf-drawer__fh"><span class="sf-drawer__aa" aria-hidden="true">Aa</span><span id="msm-fs-label">{{ __('storefront.text_size') }}</span></div>
                                    <div class="sf-seg sf-seg--fs" role="radiogroup" aria-labelledby="msm-fs-label">
                                        @foreach ($sfSizes as $step => $label)
                                            <label class="sf-seg__s" title="{{ $label }}"><input class="sf-drawer__fs-in" type="radio" name="sf-fs" value="{{ $step }}" data-fs-radio aria-label="{{ $label }}" @checked($step === 'm')><span aria-hidden="true">A</span></label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if ($sfOwnCall || $sfZaloHref !== null)
                                <div class="sf-drawer__blk sf-drawer__reach">
                                    @if ($sfOwnCall)
                                        <a class="sf-drawer__call" href="{{ $sfCallHref }}">@include('theme::partials.sf-icon', ['name' => 'phone', 'size' => 20])<span>{{ __('storefront.call_number', ['phone' => $brand['phone']]) }}</span></a>
                                    @endif
                                    @if ($sfZaloHref !== null)
                                        <a class="sf-drawer__zalo" href="{{ $sfZaloHref }}" rel="noopener"><svg class="sf-drawer__zmark" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="#0068FF" d="M12 2.5C6.2 2.5 1.5 6.3 1.5 11c0 2.6 1.4 4.9 3.7 6.5l-.9 3.4a.5.5 0 0 0 .7.6l3.9-2.1c1 .3 2 .4 3.1.4 5.8 0 10.5-3.8 10.5-8.5S17.8 2.5 12 2.5z"/><path fill="#fff" d="M7.7 6.6h8.6v1.9l-5.8 5.9h5.8v1.9H7.7v-1.9l5.8-5.9H7.7z"/></svg><span>{{ __('storefront.chat_zalo') }}</span></a>
                                    @endif
                                </div>
                            @endif
                            @if ($sfSocials !== [])
                                <div class="sf-drawer__blk sf-drawer__row">
                                    <span class="sf-drawer__fh" id="msm-social-label">{{ __('storefront.social_list') }}</span>
                                    <ul class="sf-drawer__socials" aria-labelledby="msm-social-label">
                                        @foreach ($sfSocials as $social)
                                            @php($sfSocialName = __('storefront.social', ['network' => $social['label'], 'name' => $sfName]))
                                            <li><a class="sf-drawer__social" href="{{ $social['url'] }}" rel="noopener" aria-label="{{ $sfSocialName }}" title="{{ $sfSocialName }}">@include('theme::partials.sf-icon', ['name' => $social['network'], 'size' => 20])</a></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
                @if ($sfLangSub)
                    {{-- Eight or more languages: a screen inside the panel (opened by the "Ngôn ngữ" row; a script hides it until then). --}}
                    <div class="sf-lang-sub" id="msm-lang-list" role="group" aria-labelledby="msm-lang-title" data-lang-sub>
                        <div class="sf-lang-sub__h">
                            <button type="button" class="sf-lang-back" data-lang-back>@include('theme::partials.sf-icon', ['name' => 'chevron-left', 'size' => 20])<span>{{ __('storefront.menu_word') }}</span></button>
                            <h2 class="sf-lang-sub__t" id="msm-lang-title">{{ __('storefront.language') }}</h2>
                        </div>
                        <div class="sf-lang-search">
                            @include('theme::partials.sf-icon', ['name' => 'search', 'size' => 20])
                            <label class="sr-only" for="msm-lang-q">{{ __('storefront.language_search') }}</label>
                            <input class="sf-lang-search__in" id="msm-lang-q" type="search" data-lang-q placeholder="{{ __('storefront.language_search') }}" autocomplete="off" autocapitalize="none" spellcheck="false" enterkeyhint="search">
                        </div>
                        <p class="sf-drawer__cap" id="msm-lang-now">{{ __('storefront.language_current') }}</p>
                        <ul class="sf-lang-l" aria-labelledby="msm-lang-now">
                            @foreach ($sfLanguages as $language)
                                @if ($language['current'])
                                    @include('theme::partials.drawer-lang-row', ['language' => $language])
                                @endif
                            @endforeach
                        </ul>
                        <p class="sf-drawer__cap" id="msm-lang-more" data-lang-more>{{ __('storefront.language_others') }}</p>
                        <ul class="sf-lang-l" aria-labelledby="msm-lang-more" data-lang-others>
                            @foreach ($sfLanguages as $language)
                                @if (! $language['current'])
                                    @include('theme::partials.drawer-lang-row', ['language' => $language])
                                @endif
                            @endforeach
                        </ul>
                        <p class="sf-lang-none" role="status" data-lang-none data-text="{{ __('storefront.language_none') }}"></p>
                    </div>
                @endif
            </div>
        </div>
    @endif
    {{-- Without scripts the menu drawer is simply open below the bar, and the text-size button
         (which needs a script) is not shown. --}}
    <noscript><style>@media (max-width:{{ $sfMenuXl ? '1279.98' : '1023.98' }}px){.sf-drawer[hidden]{display:block;position:static}.sf-drawer[hidden] :is(.sf-drawer__scrim,.sf-drawer__close){display:none}.sf-drawer[hidden] .sf-drawer__panel{position:static;width:auto;box-shadow:none;animation:none}}.sf-drawer__pane[hidden]{display:block}.sf-burger,.sf-fs,.sf-drawer__fs,.sf-drawer__tabs{display:none}</style></noscript>
</header>
