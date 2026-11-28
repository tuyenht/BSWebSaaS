@php
    use App\Modules\Tenancy\Domains\DomainName;
    use Illuminate\Support\Js;

    // Screen 21 "Tên miền" (design 21-ten-mien). The page is a size container: each name's block
    // lays itself out by the page's width (below 600 its buttons go last and full width; below 720
    // a spelling's role drops under its address; the pointing table becomes one card per row below
    // 600). Names wrap after a dot and are never clipped.
    $display = fn (?string $host): string => DomainName::display((string) $host);
    $host = fn (?string $h): string => str_replace('.', '.<wbr>', e($display($h)));
    $rowTones = [
        'ok' => ['text-success-text', 'ri-lock', 'admin.domains_rowst_ok'],
        'pointed' => ['text-info-text', 'ri-checkbox-circle', 'admin.domains_rowst_pointed'],
        'issuing' => ['text-info-text', 'ri-shield-keyhole', 'admin.domains_rowst_issuing'],
        'wait' => ['text-warning-text', 'ri-time', 'admin.domains_rowst_wait'],
        'nolock' => ['text-danger-text', 'ri-error-warning', 'admin.domains_rowst_nolock'],
        'lost' => ['text-danger-text', 'ri-close-circle', 'admin.domains_rowst_lost'],
        'failing' => ['text-danger-text', 'ri-link-unlink', 'admin.domains_rowst_failing'],
        'optional' => ['text-text-muted', 'ri-indeterminate-circle', 'admin.domains_rowst_optional'],
        'claim' => ['text-warning-text', 'ri-time', 'admin.domains_rowst_claim'],
    ];
    $msgTones = [
        'ok' => ['text-success-text', 'ri-checkbox-circle'],
        'warn' => ['text-warning-text', 'ri-time'],
        'danger' => ['text-danger-text', 'ri-error-warning'],
        'info' => ['text-info-text', 'ri-information'],
        'muted' => ['text-text-muted', 'ri-information'],
    ];
    $glyph = 'size-[calc(1rem+var(--user-text-boost))] shrink-0';
@endphp
<div class="@container" @if ($allowed && ! ($failed ?? false) && ($poll ?? null) !== null) wire:poll.{{ $poll }} @endif>
    @php
        // A website's own members see the page inside the settings frame (design board «Khung quản trị»,
        // section «Tên miền»), its add button ending the frame's title row; the platform console keeps
        // its own title and the button on the card.
        $inFrame = $siteUlid === null && $allowed;
        $full = $inFrame && ! ($failed ?? false) && $limit > 0 && $overview->used >= $limit;
        $addLocked = $full || ($inFrame && ! ($failed ?? false) && $locked);
    @endphp
    @if ($inFrame)
        <x-bs-admin::settings-shell active="domains" card="snug">
            @if (! ($failed ?? false) && $manage && $limit > 0)
                <x-slot:aside>
                    <x-bs-admin::button wire:click="openAdd" data-guide="add" class="shrink-0" :label="__('admin.domains_add')" :short="__('admin.domains_add_short')"
                        :aria-disabled="$addLocked ? 'true' : null" :aria-describedby="$full ? 'tm-lim-why' : null">
                        <x-bs-admin::icon name="ri-add" :class="$glyph" />
                    </x-bs-admin::button>
                </x-slot:aside>
            @endif
            <div class="@container flex flex-col gap-3.5">
                @include('admin::livewire.settings.domains-body')
            </div>
        </x-bs-admin::settings-shell>
    @else
        <div class="flex max-w-295 flex-col gap-3.5">
            {{-- Breadcrumb, title, description --}}
            <div class="min-w-0">
                <nav aria-label="{{ __('admin.breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-small text-text-muted">
                    @if ($siteUlid !== null)
                        <a href="{{ route('platform.sites.index') }}" wire:navigate class="hit-area text-primary-text hover:underline">{{ __('admin.domains_crumb_site') }}</a>
                        <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                        <a href="{{ route('platform.sites.show', $siteUlid) }}" wire:navigate class="hit-area text-primary-text hover:underline [overflow-wrap:anywhere]">{{ $console ?? '' }}</a>
                    @else
                        <span>{{ __('admin.domains_crumb_settings') }}</span>
                        <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                        <span>{{ __('admin.domains_crumb_system') }}</span>
                    @endif
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span aria-current="page" class="text-text-strong">{{ __('admin.domains_title') }}</span>
                </nav>
                <h1 class="mt-1.5 mb-1 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.domains_title') }}</h1>
                <p class="m-0 text-body text-text-muted [text-wrap:pretty]">{{ __('admin.domains_desc') }}</p>
            </div>

            @include('admin::livewire.settings.domains-body')
        </div>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$toastUndo ? 'undo' : null" :seq="$toastSeq" />
</div>
