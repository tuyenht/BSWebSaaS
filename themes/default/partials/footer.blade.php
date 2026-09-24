{{--
    Storefront footer — contentinfo landmark. NAP, legal name, license and copyright read from
    $brand (SiteIdentity: the page's language), labels from the storefront translations, colors
    from the site's tokens (storefront.css) — nothing here names a tenant, a year or a language.
    Sections are conditional so tenants without NAP data get a minimal but valid footer.
--}}
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
        <div>
            @if (! empty($brand['logo']))
                <a href="{{ $homeUrl ?? '/' }}" aria-label="{{ $brand['name'] ?? __('storefront.home') }}">
                    <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] ?? '' }}" width="140" height="36" loading="lazy" style="height:2.25rem;width:auto;margin-bottom:1rem;">
                </a>
            @endif
            @if (! empty($brand['legal_name']))
                <p class="site-footer__company">{{ $brand['legal_name'] }}</p>
            @elseif (! empty($brand['name']))
                <p class="site-footer__company">{{ $brand['name'] }}</p>
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
