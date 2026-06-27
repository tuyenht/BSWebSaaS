{{--
    Storefront footer — contentinfo landmark. NAP, legal name, license, and copyright
    all read from the $brand site-setting (set by SeedAnVinhContent for An Vinh GĐ1;
    future tenant-settings UI will let every site configure them). Sections are
    conditional so tenants without NAP data get a minimal but valid footer.
--}}
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
        <div>
            @if (! empty($brand['logo']))
                <a href="/" aria-label="{{ $brand['name'] ?? 'Trang chủ' }}">
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
                        Hotline: <a href="tel:{{ preg_replace('/[^+\d]/', '', $brand['phone']) }}">{{ $brand['phone'] }}</a><br>
                    @endif
                    @if (! empty($brand['email']))
                        Email: <a href="mailto:{{ $brand['email'] }}">{{ $brand['email'] }}</a>
                    @endif
                </address>
            @endif
            @if (! empty($brand['license']))
                <p class="site-footer__license">GPĐKKD: {{ $brand['license'] }}</p>
            @endif
        </div>
        <div>
            <p style="font-size:0.875rem;font-weight:700;color:#e2e8f0;text-transform:uppercase;letter-spacing:0.04em;margin:0 0 0.75rem;">
                Điều hướng
            </p>
            @if (! empty($menu))
                <ul style="display:flex;flex-direction:column;gap:0.375rem;">
                    @foreach ($menu as $item)
                        <li>
                            <a href="{{ $item['url'] }}" style="font-size:0.875rem;color:#94a3b8;transition:color 0.2s;" onmouseover="this.style.color='#f59e0b'" onmouseout="this.style.color='#94a3b8'">
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
    <div class="site-footer__bottom">
        @if (! empty($brand['name']))
            &copy; 2024–{{ date('Y') }} {{ $brand['name'] }}. Bảo lưu mọi quyền.
        @else
            &copy; {{ date('Y') }}. Bảo lưu mọi quyền.
        @endif
    </div>
</footer>
