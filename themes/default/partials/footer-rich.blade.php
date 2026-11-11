{{--
    Rich storefront footer (site setting `storefront.footer`, normalized into $footer). Business
    identity (name, tagline, legal name, address, contact, licence, opening hours, the Bộ Công Thương
    notice link, social links) comes from $brand
    (SiteIdentity) — never duplicated in the setting; the link columns are the menu bound to the
    footer region ($footerMenu: top-level items are the headings, their children the links), so
    every link goes through the menu pipeline and follows slug changes. The setting holds only the
    rest: about text, factories, licence issuer, copyright start year and an optional credit.
    Every value is printed through {{ }}; names set in bold are split around a placeholder, never
    printed raw. The tagline, opening hours and Bộ Công Thương lines start their directives at
    column 0, so a site without those values keeps its footer byte for byte. The footer is dark, so
    it shows the logo for a dark background (BrandImages: the normal logo when the site has none).
--}}
@php
    $sfSocials = \App\Modules\Theme\Chrome\StorefrontChrome::socials($brand['social_links'] ?? null);
    $sfName = $brand['name'] ?? null;
    $sfLegal = $brand['legal_name'] ?? $sfName;
    $sfPhone = $brand['hotline'] ?? $brand['phone'] ?? null;
    $sfTel = \App\Modules\Shared\Support\SafeLink::tel($sfPhone);
    $sfMail = \App\Modules\Shared\Support\SafeLink::mailto($brand['email'] ?? null);
    $sfIssued = null;
    if (is_string($brand['license_issued_at'] ?? null)) {
        $sfDate = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($brand['license_issued_at'], 0, 10));
        $sfIssued = $sfDate === false ? null : \App\Modules\Tenancy\Context\SiteTime::formatDay($sfDate, $brand['date_format'] ?? 'auto', app()->getLocale());
    }
    // Who issued the licence: the profile's own text in this language, else the footer setting's.
    $sfIssuer = ! empty($brand['license_text']) ? $brand['license_text'] : $footer['license_issuer'];
    $sfLicense = null;
    if (! empty($brand['license'])) {
        $sfLicense = match (true) {
            $sfIssuer !== null && $sfIssued !== null => __('storefront.license_full', ['number' => $brand['license'], 'issuer' => $sfIssuer, 'date' => $sfIssued]),
            $sfIssuer !== null => __('storefront.license_no_date', ['number' => $brand['license'], 'issuer' => $sfIssuer]),
            default => __('storefront.license', ['number' => $brand['license']]),
        };
    }
    $sfHours = \App\Modules\Theme\Chrome\StorefrontChrome::hours($brand['business_hours'] ?? null, $brand['opening_hours'] ?? null);
    $sfMoit = \App\Modules\Shared\Support\SafeLink::href($brand['moit_url'] ?? null);
    $sfMoit = $sfMoit !== null && preg_match('~\Ahttps?://~i', $sfMoit) === 1 ? $sfMoit : null;
    $sfYear = (int) date('Y');
    $sfHolder = $footer['holder'] ?? $sfName;
    $sfMark = "\u{E000}";   // placeholder for a bold name inside a translated sentence
    $sfCopyright = $footer['start_year'] !== null && $footer['start_year'] < $sfYear
        ? __('storefront.copyright_range', ['from' => $footer['start_year'], 'to' => $sfYear, 'name' => $sfMark])
        : __('storefront.copyright', ['year' => $sfYear, 'name' => $sfMark]);
    $sfCredit = $footer['credit'] !== null ? __('storefront.credit', ['name' => $sfMark]) : null;
@endphp
<footer class="sf-footer" role="contentinfo">
    <div class="sf-container sf-footer__top">
        <div class="sf-footer__brand">
            @if (! empty($brand['logo_dark'] ?? $brand['logo'] ?? null))
                <a class="sf-footer__logo-link" href="{{ $homeUrl ?? '/' }}">
                    <img class="sf-footer__logo" src="{{ $brand['logo_dark'] ?? $brand['logo'] }}" alt="{{ $sfName ?? __('storefront.home') }}" width="156" height="40" loading="lazy">
                </a>
            @endif
@if (! empty($brand['tagline']))
            <p class="sf-footer__tagline">{{ $brand['tagline'] }}</p>
@endif
            @if ($footer['about'] !== null)
                <p class="sf-footer__about">{{ $footer['about'] }}</p>
            @endif
            @if ($sfSocials !== [])
                <ul class="sf-footer__socials">
                    @foreach ($sfSocials as $social)
                        <li>
                            <a class="sf-footer__social" href="{{ $social['url'] }}" rel="noopener" aria-label="{{ __('storefront.social', ['network' => $social['label'], 'name' => $sfName ?? '']) }} {{ __('storefront.external') }}">@include('theme::partials.sf-icon', ['name' => $social['network'], 'size' => 24])</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        @foreach (($footerMenu ?? []) as $column)
            <div class="sf-footer__column{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($column) }}">
                <h2 class="sf-footer__heading">
                    @if ($column['url'] !== '#')
                        <a href="{{ $column['url'] }}">{{ $column['label'] }}</a>
                    @else
                        {{ $column['label'] }}
                    @endif
                </h2>
                @if (! empty($column['children']))
                    <ul class="sf-footer__links">
                        @foreach ($column['children'] as $link)
                            @continue($link['url'] === '#')
                            <li class="{{ trim(\App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($link)) }}">
                                <a class="sf-footer__link" href="{{ $link['url'] }}" @if (! empty($link['active'])) aria-current="page" @endif @foreach (($link['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($link) }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    @if ($sfLegal !== null || ! empty($brand['address']) || $sfLicense !== null || $sfHours !== null || $sfMoit !== null || $footer['factories'] !== [])
        <div class="sf-footer__company">
            <div class="sf-container">
                <div class="sf-footer__legal">
                    @if ($sfLegal !== null)
                        <h2 class="sf-footer__legal-name">{{ $sfLegal }}</h2>
                    @endif
                    <ul class="sf-footer__facts">
                        @if (! empty($brand['address']))
                            <li class="sf-footer__fact">@include('theme::partials.sf-icon', ['name' => 'address', 'size' => 20])<span>{{ __('storefront.head_office') }}: {{ $brand['address'] }}</span></li>
                        @endif
                        @if ($sfLicense !== null)
                            <li class="sf-footer__fact">@include('theme::partials.sf-icon', ['name' => 'license', 'size' => 20])<span>{{ $sfLicense }}</span></li>
                        @endif
@if ($sfHours !== null)
                        <li class="sf-footer__fact">@include('theme::partials.sf-icon', ['name' => 'clock', 'size' => 20])<span>{{ __('storefront.hours_label') }}: {{ $sfHours }}</span></li>
@endif
@if ($sfMoit !== null)
                        <li class="sf-footer__fact">@include('theme::partials.sf-icon', ['name' => 'verified', 'size' => 20])<a href="{{ $sfMoit }}" rel="noopener">{{ __('storefront.moit') }}<span class="sr-only"> {{ __('storefront.external') }}</span></a></li>
@endif
                        @if ($footer['show_contact'] && ($sfTel !== null || $sfMail !== null))
                            <li class="sf-footer__fact sf-footer__fact--row">
                                @if ($sfTel !== null)
                                    <span class="sf-footer__fact">@include('theme::partials.sf-icon', ['name' => 'phone', 'size' => 20])<span>{{ __('storefront.hotline') }}: <a href="{{ $sfTel }}">{{ $sfPhone }}</a></span></span>
                                @endif
                                @if ($sfMail !== null)
                                    <span class="sf-footer__fact">@include('theme::partials.sf-icon', ['name' => 'email', 'size' => 20])<span>{{ __('storefront.email') }}: <a href="{{ $sfMail }}">{{ $brand['email'] }}</a></span></span>
                                @endif
                            </li>
                        @endif
                    </ul>
                </div>
                @if ($footer['factories'] !== [])
                    <ul class="sf-footer__factories" data-sf-rot role="list" aria-label="{{ __('storefront.factories_label') }}">
                        @foreach ($footer['factories'] as $factory)
                            <li class="sf-factory">
                                <div class="sf-factory__head">
                                    <span class="sf-factory__icon">@include('theme::partials.sf-icon', ['name' => 'building', 'size' => 32, 'stroke' => 1.5])</span>
                                    <div class="sf-factory__titles">
                                        <div class="sf-factory__title-row">
                                            <h3 class="sf-factory__title">{{ $factory['title'] }}</h3>
                                            @if ($factory['area'] !== null)
                                                <span class="sf-factory__chip">{{ __('storefront.factory_area', ['area' => number_format($factory['area'], 0, ',', '.')]) }}</span>
                                            @endif
                                        </div>
                                        @if ($factory['name'] !== null)
                                            <p class="sf-factory__name">{{ $factory['name'] }}</p>
                                        @endif
                                        @if ($factory['area'] !== null)
                                            <p class="sf-factory__area">{{ __('storefront.factory_area', ['area' => number_format($factory['area'], 0, ',', '.')]) }}</p>
                                        @endif
                                    </div>
                                </div>
                                @if ($factory['address'] !== null)
                                    <p class="sf-factory__address">@include('theme::partials.sf-icon', ['name' => 'address', 'size' => 20])<span>{{ $factory['address'] }}</span></p>
                                @endif
                                @if ($factory['map_url'] !== null)
                                    <a class="sf-factory__map" href="{{ $factory['map_url'] }}" rel="noopener" aria-label="{{ __('storefront.view_map_of', ['name' => $factory['title']]) }} {{ __('storefront.external') }}">@include('theme::partials.sf-icon', ['name' => 'map', 'size' => 16])<span>{{ __('storefront.view_map') }}</span></a>
                                @elseif ($factory['directions_url'] !== null)
                                    <a class="sf-factory__map" href="{{ $factory['directions_url'] }}" target="_blank" rel="noopener" aria-label="{{ __('storefront.directions_to', ['name' => $factory['title']]) }} {{ __('storefront.external') }}">@include('theme::partials.sf-icon', ['name' => 'map', 'size' => 16])<span>{{ __('storefront.directions') }}</span></a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if (count($footer['factories']) > 1)
                        <div class="sf-rot__nav" data-sf-rot-nav hidden>
                            <button type="button" class="sf-rot__arrow" data-sf-rot-prev aria-label="{{ __('storefront.factory_prev') }}">@include('theme::partials.sf-icon', ['name' => 'chevron-left', 'size' => 16])</button>
                            @foreach ($footer['factories'] as $factory)
                                <button type="button" class="sf-rot__go" data-sf-rot-go aria-label="{{ $factory['title'] }}"@if ($loop->first) aria-current="true"@endif><span class="sf-rot__dot"></span></button>
                            @endforeach
                            <button type="button" class="sf-rot__arrow" data-sf-rot-next aria-label="{{ __('storefront.factory_next') }}">@include('theme::partials.sf-icon', ['name' => 'chevron-right', 'size' => 16])</button>
                        </div>
                        @vite(['resources/js/sf-footer-rot.js'])
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="sf-footer__bottom">
        <div class="sf-container">
            <p>
                @if ($sfHolder !== null)
                    @php([$sfBefore, $sfAfter] = array_pad(explode($sfMark, $sfCopyright, 2), 2, ''))
                    {{ $sfBefore }}<strong>{{ $sfHolder }}</strong>{{ $sfAfter }}
                @else
                    {{ __('storefront.rights_unnamed', ['year' => $sfYear]) }}
                @endif
                @if ($sfCredit !== null)
                    @php([$sfBefore, $sfAfter] = array_pad(explode($sfMark, $sfCredit, 2), 2, ''))
                    <span class="sf-footer__credit">- {{ $sfBefore }}@if ($footer['credit']['url'] !== null)<a href="{{ $footer['credit']['url'] }}" rel="noopener"><strong>{{ $footer['credit']['label'] }}</strong></a>@else<strong>{{ $footer['credit']['label'] }}</strong>@endif{{ $sfAfter }}</span>
                @endif
            </p>
        </div>
    </div>
</footer>
