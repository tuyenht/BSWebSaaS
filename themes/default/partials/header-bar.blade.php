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
    $drawerMenu = ! empty($mobileMenu) ? $mobileMenu : ($menu ?? []);
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
    $sfMenuXl = ! empty($menu) && \App\Modules\Theme\Chrome\StorefrontChrome::menuNeedsXl($menu);
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
        // that bar shows (under 768px; it carries the call itself), so a tablet keeps it; search and cart
        // draw nothing until the site has them.
        $sfCallHref = \App\Modules\Shared\Support\SafeLink::tel($brand['phone'] ?? null);
        $sfCorner = ($header['mobile_corner'] ?? 'call') === 'call' && $sfCallHref !== null;
        $sfEmailHref = \App\Modules\Shared\Support\SafeLink::mailto($brand['email'] ?? null);
    @endphp
    <div class="sf-bar">
        <div class="sf-container sf-bar__inner{{ ! empty($drawerMenu) ? ' sf-bar__inner--drawer' : '' }}">
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
                    @include('theme::partials.nav-disclosure', ['items' => $menu, 'depth' => 0, 'idPrefix' => 'dsm'])
                </nav>
            @endif
            @if (! empty($drawerMenu))
                <span class="sf-bar__corner">@if ($sfCorner)<a class="sf-bar__call{{ $sfBar ? ' sf-bar__call--hasbar' : '' }}" href="{{ $sfCallHref }}" aria-label="{{ __('storefront.call_number', ['phone' => $brand['phone']]) }}">@include('theme::partials.sf-icon', ['name' => 'phone', 'size' => 24])</a>@endif</span>
            @endif
        </div>
    </div>
    @if (! empty($drawerMenu))
        {{-- The phone menu: a dialog drawer (focus moves in on open and back to the button on close, Esc,
             the dimmed area and the ✕ close it). No title word in it: the page's own words are the menu. --}}
        <div id="mobile-menu" class="sf-drawer" role="dialog" aria-modal="true" aria-label="{{ __('storefront.menu_word') }}" hidden>
            <div class="sf-drawer__scrim" data-menu-close></div>
            <button type="button" class="sf-drawer__close" data-menu-close aria-label="{{ __('storefront.menu_close') }}">@include('theme::partials.sf-icon', ['name' => 'close', 'size' => 24])</button>
            <div class="sf-drawer__panel">
                @php
                    // "Sản phẩm" tab: only with a product list and a category under it (PageChrome → DrawerProducts).
                    $sfTabs = ! empty($drawerProducts);
                    $sfName = $brand['name'] ?? '';
                    $sfSocials = \App\Modules\Theme\Chrome\StorefrontChrome::socials($brand['social_links'] ?? null);
                    $sfHours = \App\Modules\Theme\Chrome\StorefrontChrome::hours($brand['business_hours'] ?? null, $brand['opening_hours'] ?? null);
                    // Zalo shows when the floating contact has a Zalo channel, with the link that button uses.
                    $sfZaloHref = null;
                    foreach (['buttons', 'bar', 'sheet'] as $sfSet) {
                        foreach (($floatingContact[$sfSet] ?? []) as $sfChannel) {
                            if (($sfChannel['key'] ?? null) === 'zalo' && empty($sfChannel['pending']) && ($sfChannel['href'] ?? '') !== '') {
                                $sfZaloHref = $sfChannel['href'];
                                break 2;
                            }
                        }
                    }
                @endphp
                @if ($sfTabs)
                    <div class="sf-drawer__tabs" role="tablist" aria-label="{{ __('storefront.drawer_tabs_label') }}" data-drawer-tabs>
                        <button type="button" class="sf-drawer__tab" role="tab" id="msm-tab-menu" aria-controls="msm-pane-menu" aria-selected="true">{{ __('storefront.tab_menu') }}</button>
                        <button type="button" class="sf-drawer__tab" role="tab" id="msm-tab-products" aria-controls="msm-pane-products" aria-selected="false" tabindex="-1">{{ __('storefront.tab_products') }}</button>
                    </div>
                @endif
                <div class="sf-drawer__pane"@if ($sfTabs) role="tabpanel" id="msm-pane-menu" aria-labelledby="msm-tab-menu"@endif>
                    <nav class="sf-nav sf-nav--mob" aria-label="{{ __('storefront.nav_main') }}">
                        @include('theme::partials.nav-disclosure', ['items' => $drawerMenu, 'depth' => 0, 'idPrefix' => 'msm'])
                    </nav>
                </div>
                @if ($sfTabs)
                    <div class="sf-drawer__pane" role="tabpanel" id="msm-pane-products" aria-labelledby="msm-tab-products" hidden>
                        <nav class="sf-nav sf-nav--mob" aria-label="{{ __('storefront.nav_products') }}">
                            @include('theme::partials.nav-disclosure', ['items' => $drawerProducts['items'], 'depth' => 0, 'idPrefix' => 'psm'])
                        </nav>
                        <a class="sf-drawer__all" href="{{ $drawerProducts['url'] }}"><span>{{ __('storefront.products_all') }}</span>@include('theme::partials.sf-icon', ['name' => 'chevron-right', 'size' => 20])</a>
                    </div>
                @endif
                @if ($sfLanguages !== [] || $header['text_size'])
                    <div class="sf-drawer__tools">
                        @if ($sfLanguages !== [])
                            <div class="sf-drawer__set">
                                <span class="sf-drawer__set-l" aria-hidden="true">{{ __('storefront.language') }}</span>
                                <nav class="sf-drawer__langs" aria-label="{{ __('storefront.language') }}">
                                    <ul>
                                        @foreach ($sfLanguages as $language)
                                            <li>
                                                <a class="sf-drawer__lang" href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" dir="{{ $language['dir'] }}" @if ($language['current']) aria-current="true" @endif>
                                                    <span>{{ $language['name'] }}@if ($language['fallback']) <small class="sf-drawer__hint">{{ __('storefront.lang_home') }}</small>@endif</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </nav>
                            </div>
                        @endif
                        @if ($header['text_size'])
                            {{-- Three joined cells showing the letter A in fixed sizes; a script sets the page's size (the group is hidden without one). --}}
                            <fieldset class="sf-drawer__fs" data-fs-set>
                                <legend>{{ __('storefront.text_size') }}</legend>
                                <span class="sf-drawer__fs-cells">
                                    @foreach ($sfSizes as $step => $label)
                                        <label class="sf-drawer__fs-c sf-drawer__fs-c--{{ $step }}"><input class="sf-drawer__fs-in" type="radio" name="sf-fs" value="{{ $step }}" data-fs-radio aria-label="{{ $label }}" title="{{ $label }}" @checked($step === 'm')><span aria-hidden="true">A</span></label>
                                    @endforeach
                                </span>
                            </fieldset>
                        @endif
                    </div>
                @endif
                @if ($sfCallHref !== null || $sfZaloHref !== null || $sfEmailHref !== null || $sfHours !== null || $sfSocials !== [])
                    <div class="sf-drawer__contact">
                        @if ($sfCallHref !== null)
                            <a class="sf-drawer__call" href="{{ $sfCallHref }}">@include('theme::partials.sf-icon', ['name' => 'phone', 'size' => 20])<span>{{ __('storefront.call_number', ['phone' => $brand['phone']]) }}</span></a>
                        @endif
                        @if ($sfZaloHref !== null)
                            <a class="sf-drawer__zalo" href="{{ $sfZaloHref }}" rel="noopener">@include('theme::partials.sf-icon', ['name' => 'zalo', 'size' => 20])<span>{{ __('storefront.chat_zalo') }}</span></a>
                        @endif
                        @if ($sfEmailHref !== null)
                            <a class="sf-drawer__line" href="{{ $sfEmailHref }}">@include('theme::partials.sf-icon', ['name' => 'email', 'size' => 20])<span>{{ $brand['email'] }}</span></a>
                        @endif
                        @if ($sfHours !== null)
                            <p class="sf-drawer__line sf-drawer__line--hours">@include('theme::partials.sf-icon', ['name' => 'clock', 'size' => 20])<span>{{ $sfHours }}</span></p>
                        @endif
                        @if ($sfSocials !== [])
                            <ul class="sf-drawer__socials" aria-label="{{ __('storefront.social_list') }}">
                                @foreach ($sfSocials as $social)
                                    @php($sfSocialName = __('storefront.social', ['network' => $social['label'], 'name' => $sfName]))
                                    <li><a class="sf-drawer__social" href="{{ $social['url'] }}" rel="noopener" aria-label="{{ $sfSocialName }}" title="{{ $sfSocialName }}">@include('theme::partials.sf-icon', ['name' => $social['network'], 'size' => 22])</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
    {{-- Without scripts the menu drawer is simply open below the bar, and the text-size button
         (which needs a script) is not shown. --}}
    <noscript><style>@media (max-width:{{ $sfMenuXl ? '1279.98' : '1023.98' }}px){.sf-drawer[hidden]{display:block;position:static}.sf-drawer[hidden] :is(.sf-drawer__scrim,.sf-drawer__close){display:none}.sf-drawer[hidden] .sf-drawer__panel{position:static;width:auto;box-shadow:none;animation:none}}.sf-burger,.sf-fs,.sf-drawer__fs,.sf-drawer__tabs{display:none}</style></noscript>
</header>
