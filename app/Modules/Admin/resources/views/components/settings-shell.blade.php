@props(['active' => '', 'unsaved' => null, 'save' => 'save', 'discard' => 'resetChanges', 'hints' => [], 'head' => true])
@php
    // The section's title row: its name, then an (i) button stuck to it (the same button and in-place
    // note as a field's explanation) that opens "Về mục <name>" right under the row, with the full
    // description and every first-time explanation the member has put away with "Đã hiểu"; under the
    // row, one short line of description (hidden while the note is open, which holds the full one).
    // A page passes `hints` (FirstHints key => the sentence as it shows on that page) for the
    // explanations it offers; only the dismissed ones go into the note.
    // `head` false leaves the row out for a page whose own card header still names the section.
    // A section that keeps its draft on the server joins the shared unsaved-changes dialog by
    // passing its change count (`:unsaved="$changes"`) and, when they are named otherwise, its save
    // and discard methods. Leaving it then asks first; a section that passes nothing leaves freely.
    if (\App\Modules\Admin\Support\AdminPlane::isPlatform()) {
        // Platform (system) console — super_admin-only system defaults.
        $nav = [[
            'group' => __('admin.settings_system'),
            'items' => array_values(array_filter([
                Route::has('platform.settings.email') ? ['key' => 'system-email', 'route' => 'platform.settings.email', 'label' => __('admin.system_email')] : null,
                Route::has('platform.settings.security') ? ['key' => 'login-security', 'route' => 'platform.settings.security', 'label' => __('admin.system_login_security')] : null,
                Route::has('platform.settings.social') ? ['key' => 'system-social', 'route' => 'platform.settings.social', 'label' => __('admin.system_social')] : null,
            ])),
        ]];
    } else {
        $nav = [[
            'group' => __('admin.settings_account'),
            'items' => [
                ['key' => 'security', 'route' => 'admin.account', 'label' => __('admin.security')],
            ],
        ]];

        // Site-wide settings appear only for roles holding `settings.manage` (owner/admin/
        // super_admin). A normal member sees only the "My Account" group.
        $workspace = [];
        if (\Illuminate\Support\Facades\Gate::allows('settings.manage')) {
            if (Route::has('admin.settings.general')) {
                $workspace[] = ['key' => 'general', 'route' => 'admin.settings.general', 'label' => __('admin.gs.title')];
            }
            if (Route::has('admin.settings.seo')) {
                $workspace[] = ['key' => 'seo', 'route' => 'admin.settings.seo', 'label' => __('admin.seoset.title')];
            }
            if (Route::has('admin.settings.email')) {
                $workspace[] = ['key' => 'email', 'route' => 'admin.settings.email', 'label' => __('admin.email_settings')];
            }
            if (Route::has('admin.settings.social')) {
                $workspace[] = ['key' => 'social', 'route' => 'admin.settings.social', 'label' => __('admin.social_login')];
            }
            if (Route::has('admin.settings.appearance')) {
                $workspace[] = ['key' => 'appearance', 'route' => 'admin.settings.appearance', 'label' => __('admin.appearance')];
            }
            if (Route::has('admin.settings.catalog')) {
                $workspace[] = ['key' => 'catalog', 'route' => 'admin.settings.catalog', 'label' => __('admin.catalog_settings')];
            }
            if (Route::has('admin.settings.floating-contact')) {
                $workspace[] = ['key' => 'floating', 'route' => 'admin.settings.floating-contact', 'label' => __('admin.fc.title')];
            }
            if (Route::has('admin.settings.integrations')) {
                $workspace[] = ['key' => 'integrations', 'route' => 'admin.settings.integrations', 'label' => __('admin.int.title')];
            }
            // Operator-only: the approval-workflow switchboard (north-star — super_admin
            // enables approval per site × per content type; tenant roles never see it).
            if (Route::has('admin.settings.workflows') && \Illuminate\Support\Facades\Gate::allows('platform.workflows.manage')) {
                $workspace[] = ['key' => 'workflow', 'route' => 'admin.settings.workflows', 'label' => __('admin.workflow_settings')];
            }
        }
        // Domains are readable with domains.view alone (changes need domains.manage on the page),
        // on a site entitled to its own domains — entitlement before RBAC, as the admin nav does.
        // The Domains page itself refuses a site that is not entitled, so there it is not asked twice.
        if (Route::has('admin.settings.domains')
            && (request()->routeIs('admin.settings.domains')
                || in_array('domains', app(\App\Modules\Tenancy\Entitlement\Entitlement::class)->entitledModuleKeys(), true))
            && \Illuminate\Support\Facades\Gate::allows('domains.view')) {
            $workspace[] = ['key' => 'domains', 'route' => 'admin.settings.domains', 'label' => __('admin.domains')];
        }
        if ($workspace !== []) {
            $nav[] = ['group' => __('admin.settings_workspace'), 'items' => $workspace];
        }
    }
    $items = array_merge(...array_column($nav, 'items'));
    $current = collect($items)->firstWhere('key', $active);
    $sectionName = (string) ($current['label'] ?? '');
    $short = \Illuminate\Support\Facades\Lang::has('admin.set.sd.'.$active) ? __('admin.set.sd.'.$active) : '';
    $full = \Illuminate\Support\Facades\Lang::has('admin.set.desc.'.$active) ? __('admin.set.desc.'.$active) : '';
    $learned = array_values(array_filter((array) $hints, static fn (mixed $text, mixed $key): bool => is_string($key) && \App\Modules\Admin\Support\FirstHints::dismissed($key) && is_string($text) && $text !== '', ARRAY_FILTER_USE_BOTH));
    $hasInfo = $full !== '' || $learned !== [];
    $infoGlyph = 'size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]';
    // A page with this column folds the main menu to its icon rail so the column and the section
    // get the room: the layout prints data-sidebar-fold on <html> (the shell renders before it),
    // and app.js keeps it in step across wire:navigate through [data-set-shell]. Per request only.
    request()->attributes->set('admin.sidebar_fold', true);
@endphp
<div class="mx-auto max-w-5xl" data-set-shell>
    <h1 class="mb-5 text-lg font-semibold text-heading">{{ \App\Modules\Admin\Support\AdminPlane::isPlatform() ? __('admin.settings_system') : __('admin.settings') }}</h1>

    <div class="grid gap-6 lg:grid-cols-[210px_1fr]">
        {{-- Section nav: vertical on desktop (a long name wraps inside the column), horizontally
             scrollable on mobile. min-w-0 lets the grid column shrink to the screen so the strip
             scrolls inside itself, not the page. --}}
        <nav class="min-w-0 lg:sticky lg:top-20 lg:self-start">
            <div class="-mx-1 flex gap-1 overflow-x-auto pb-1 lg:mx-0 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:pb-0">
                @foreach ($nav as $group)
                    <p class="hidden shrink-0 px-2 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-placeholder first:lg:pt-0 lg:block">{{ $group['group'] }}</p>
                    @foreach ($group['items'] as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate
                           @class([
                               'shrink-0 whitespace-nowrap rounded px-3 py-2 text-sm font-medium transition-colors lg:whitespace-normal',
                               'bg-admin-primary/10 text-accent' => $active === $item['key'],
                               'text-body-muted hover:bg-surface-hover' => $active !== $item['key'],
                           ])>{{ $item['label'] }}</a>
                    @endforeach
                @endforeach
            </div>
        </nav>

        {{-- Section content --}}
        <div class="min-w-0">
            @if ($head && $sectionName !== '')
                <div class="mb-5 flex flex-col gap-1 max-md:mb-2" x-data="{ secInfo: false }"
                     x-on:keydown.escape="if (secInfo) { secInfo = false; $refs.secInfoBtn.focus() }">
                    <div class="flex min-w-0 items-start gap-x-0.5">
                        <h2 class="min-w-0 py-[max(0rem,calc((var(--spacing-control)-1.4em)/2))] text-[min(1.375rem,calc(1rem+var(--user-text-boost)))] leading-[1.4] font-semibold text-text-strong [overflow-wrap:break-word] [text-wrap:pretty]">{{ $sectionName }}</h2>
                        @if ($hasInfo)
                            <button type="button" x-ref="secInfoBtn" data-set-info
                                    class="hit-area inline-flex size-control shrink-0 items-center justify-center rounded-md text-text-muted hover:text-primary-text aria-expanded:text-primary-text"
                                    x-on:click="secInfo = ! secInfo" aria-expanded="false" x-bind:aria-expanded="secInfo ? 'true' : 'false'" aria-controls="set-info"
                                    title="{{ __('admin.set.info_aria', ['name' => $sectionName]) }}" aria-label="{{ __('admin.set.info_aria', ['name' => $sectionName]) }}">
                                <x-bs-admin::icon name="ri-information" :class="$infoGlyph" />
                            </button>
                        @endif
                    </div>
                    @if ($hasInfo)
                        <div id="set-info" role="note" aria-labelledby="set-info-t" x-show="secInfo" x-cloak
                             class="max-w-[40rem] rounded-lg border border-border bg-surface px-3 py-2.5 text-caption leading-relaxed text-text [overflow-wrap:break-word]">
                            <span id="set-info-t" class="mb-1 block font-semibold text-text-strong">{{ __('admin.set.info_title', ['name' => $sectionName]) }}</span>
                            @if ($full !== '')
                                <span class="block">{{ $full }}</span>
                            @endif
                            @foreach ($learned as $text)
                                <span @class(['flex items-start gap-1.5', 'mt-1.5' => $full !== '' || ! $loop->first]) data-set-learned>
                                    <x-bs-admin::icon name="ri-star-fill" class="mt-px size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 text-warning-base" />
                                    <span class="min-w-0">{{ $text }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @if ($short !== '')
                        <p class="max-w-[640px] text-[calc(0.78125rem+var(--user-text-boost))] leading-[1.55] text-text-muted" @if ($hasInfo) x-show="! secInfo" @endif>{{ $short }}</p>
                    @endif
                </div>
            @endif
            <div class="space-y-6">{{ $slot }}</div>
        </div>
    </div>

    @if ($unsaved !== null)
        @php
            $paths = array_map(static fn (array $item): string => (string) parse_url(route($item['route']), PHP_URL_PATH), $items);
        @endphp
        <x-bs-admin::unsaved-dialog :count="(int) $unsaved" :label="$current['label'] ?? ''" :save="$save" :discard="$discard" :sections="$paths" />
    @endif
</div>
