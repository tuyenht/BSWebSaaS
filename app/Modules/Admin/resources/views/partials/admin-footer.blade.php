{{-- The admin footer of both planes (website admin and platform console): "© year {platform} · Xây dựng trên nền tảng {kit} v{version}." on the left and, when the
     platform shows it, "Phát triển bởi {name}" on the right; every name comes from config('admin.brand.*')
     (white-label). Text at the caption size, so it follows the reader's text size. Each link keeps its
     look and gets a 44px hit area through a transparent extension; a wrapped second line starts below
     that area, never under it. --}}
@php
    $footerLink = 'hit-area inline-block font-medium text-primary-text transition-colors hover:underline';
    $builtBy = config('admin.brand.built_by');
@endphp
<footer class="border-t border-border bg-surface px-4 py-4 text-caption text-footer-fg lg:px-6">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-3.5">
        <span>&copy; {{ now()->year }}
            <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLink }}">{{ config('admin.brand.name') }}</a>
            · {{ __('admin.footer_built_on') }}
            <a href="{{ config('admin.brand.kit_url') }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLink }}">{{ config('admin.brand.kit') }}</a>
            v{{ config('admin.brand.version') }}.
        </span>
        @if (! empty($builtBy['show']) && filled($builtBy['name'] ?? null))
            <span>{{ __('admin.footer_built_by') }}
                <a href="{{ $builtBy['url'] }}" target="_blank" rel="noopener noreferrer" class="{{ $footerLink }}">{{ $builtBy['name'] }}</a>
            </span>
        @endif
    </div>
</footer>
