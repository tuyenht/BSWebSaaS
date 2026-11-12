{{--
    Storefront footer — contentinfo landmark. NAP, legal name, license and copyright read from
    $brand (SiteIdentity: the page's language), labels from the storefront translations, colors
    from the site's tokens (storefront.css) — nothing here names a tenant, a year or a language.
    Sections are conditional so tenants without NAP data get a minimal but valid footer. The
    tagline, opening hours and Bộ Công Thương lines start their directives at column 0, so a site
    without those values keeps its footer byte for byte.
    A site with the `storefront.footer` setting gets the rich footer instead ($footer, normalized).
    The floating contact buttons and the site's analytics beacon follow it, once per page (every
    layout prints this footer last in its body).
    The footer is dark, so it shows the logo for a dark background (BrandImages: the normal logo
    when the site has none).
--}}
@if (! empty($footer))
@include('theme::partials.footer-rich')
@else
@php
    $sfHours = \App\Modules\Theme\Chrome\StorefrontChrome::hours($brand['business_hours'] ?? null, $brand['opening_hours'] ?? null);
    $sfMoit = \App\Modules\Shared\Support\SafeLink::href($brand['moit_url'] ?? null);
    $sfMoit = $sfMoit !== null && preg_match('~\Ahttps?://~i', $sfMoit) === 1 ? $sfMoit : null;
@endphp
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
        <div>
            @if (! empty($brand['logo_dark'] ?? $brand['logo'] ?? null))
                <a href="{{ $homeUrl ?? '/' }}" aria-label="{{ $brand['name'] ?? __('storefront.home') }}">
                    <img src="{{ $brand['logo_dark'] ?? $brand['logo'] }}" alt="{{ $brand['name'] ?? '' }}" width="140" height="36" loading="lazy" decoding="async" fetchpriority="low" style="height:2.25rem;width:auto;margin-bottom:1rem;">
                </a>
            @endif
            @if (! empty($brand['legal_name']))
                <p class="site-footer__company">{{ $brand['legal_name'] }}</p>
            @elseif (! empty($brand['name']))
                <p class="site-footer__company">{{ $brand['name'] }}</p>
            @endif
@if (! empty($brand['tagline']))
            <p class="site-footer__tagline">{{ $brand['tagline'] }}</p>
@endif
            @if (! empty($brand['address']) || ! empty($brand['phone']) || ! empty($brand['email']))
                <address class="site-footer__nap" style="font-style:normal;">
                    @if (! empty($brand['address']))
                        {{ $brand['address'] }}<br>
                    @endif
                    @if (! empty($brand['phone']))
                        {{ __('storefront.hotline') }}: <a href="tel:{{ preg_replace('/[^+\d]/', '', $brand['phone']) }}">{{ $brand['phone'] }}</a><br>
                    @endif
                    @if (! empty($brand['email']))
                        {{ __('storefront.email') }}: <a href="mailto:{{ $brand['email'] }}">{{ $brand['email'] }}</a>
                    @endif
                </address>
            @endif
            @if (! empty($brand['license']))
                <p class="site-footer__license">{{ __('storefront.license', ['number' => $brand['license']]) }}</p>
            @endif
@if ($sfHours !== null)
            <p class="site-footer__license">{{ __('storefront.hours_label') }}: {{ $sfHours }}</p>
@endif
@if ($sfMoit !== null)
            <p class="site-footer__license"><a class="site-footer__link" href="{{ $sfMoit }}" rel="noopener">{{ __('storefront.moit') }}<span class="sr-only"> {{ __('storefront.external') }}</span></a></p>
@endif
        </div>
        <div>
            <p class="site-footer__heading">{{ __('storefront.nav_footer') }}</p>
            @if (! empty($menu))
                <ul class="site-footer__links">
                    @foreach ($menu as $item)
                        <li>
                            <a class="site-footer__link" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
    <div class="site-footer__bottom">
        @if (! empty($brand['name']))
            {{ __('storefront.rights', ['year' => date('Y'), 'name' => $brand['name']]) }}
        @else
            {{ __('storefront.rights_unnamed', ['year' => date('Y')]) }}
        @endif
    </div>
</footer>
@endif
@include('theme::partials.floating-contact')
@include('theme::partials.web-analytics')
