@php
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    // Screen 18 "Quy trình duyệt" (design 18-quy-trinh-duyet): which approval flow each content type
    // uses. Picks and switches are staged in the page (resources/js/admin-workflow-settings.js) and
    // saved together by "Lưu — hiệu lực ngay". Every row and every dialog is drawn here, server
    // side; the page script only shows, hides and fills them from its staged state, so each part
    // starts out the way the saved state shows it. Layout by the page's width: admin-workflow.css.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $smallGlyph = 'size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0';
    $hidden = fn (bool $hide): string => $hide ? 'style="display: none"' : '';
    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale()) ?: (string) $n;

    $names = collect($templates)->pluck('name', 'key')->all();
    $onCount = collect($rows)->filter(fn (array $row): bool => $row['template'] !== null && $row['active'])->count();
    $total = count($rows);
    $planLine = $site['plan'] !== null ? __('admin.wf.plan_line', ['plan' => $site['plan']]) : __('admin.wf.plan_none');
    $kindIcons = ['initial' => 'ri-draft', 'middle' => 'ri-user-search', 'terminal' => 'ri-archive', 'published' => 'ri-global'];

    $state = [
        'rows' => array_map(fn (array $row): array => [
            'key' => $row['key'],
            'label' => $row['label'],
            'pending' => $row['pending'],
            'template' => $row['template'],
            'active' => $row['active'],
        ], $rows),
        'templates' => collect($templates)->mapWithKeys(fn (array $t): array => [$t['key'] => ['name' => $t['name'], 'locked' => $t['locked']]])->all(),
        'texts' => collect([
            'summary', 'summary_short', 'switch_label', 'enabled', 'disabled', 'toggle_off_title', 'toggle_on_title',
            'pick_title', 'apply_why_empty', 'apply_why_same', 'apply_none', 'apply_new', 'apply',
            'cf_none_title', 'cf_none_text', 'cf_none_ok', 'cf_swap_title', 'swap_confirm', 'swap_go',
            'cf_off_title', 'turn_off_confirm', 'turn_off', 'leave_text',
        ])->mapWithKeys(fn (string $key): array => [$key => __('admin.wf.'.$key)])->all(),
    ];
@endphp
<div class="wf-page"
     x-data="workflowSettings"
     x-effect="document.body.style.overflow = (pick || confirm || up || leave) ? 'hidden' : ''"
     x-on:keydown.escape.window="escape()"
     x-on:livewire:navigate.document="guardNavigate($event)">
    <script type="application/json" data-wf-state>@json($state)</script>

    <div class="flex max-w-295 flex-col gap-4">
        {{-- Breadcrumb, title, description, reload --}}
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-[1_1_20rem] flex-col">
                <nav aria-label="{{ __('admin.breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-caption text-text-muted">
                    <span>{{ __('admin.wf.crumb_settings') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span aria-current="page" class="text-text-strong">{{ __('admin.workflow_settings') }}</span>
                </nav>
                <h1 class="mt-1.5 mb-1 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.workflow_settings') }}</h1>
                <p class="m-0 text-small leading-relaxed text-text-muted [text-wrap:pretty]">{{ __('admin.wf.desc') }}</p>
            </div>
            <div class="flex flex-wrap gap-2 min-[37.5rem]:mt-5.5">
                <x-bs-admin::button variant="outline" color="secondary" wire:click="$refresh" wire:loading.attr="disabled" wire:target="$refresh">
                    <x-bs-admin::icon name="ri-refresh" :class="$glyph" />
                    <span>{{ __('admin.wf.reload') }}</span>
                </x-bs-admin::button>
            </div>
        </div>

        @error('changes')
            <x-bs-admin::alert color="danger" icon="ri-error-warning">{{ $message }}</x-bs-admin::alert>
        @enderror

        {{-- While "Tải lại" runs: the list's outline with placeholder lines --}}
        <div wire:loading.block wire:target="$refresh" aria-busy="true" class="rounded-md border border-border bg-surface shadow-card">
            <div class="border-b border-border px-4 py-3">
                <h2 class="m-0 text-body font-semibold text-text-strong">{{ __('admin.wf.list_title') }}</h2>
            </div>
            <div class="wf-grid wf-head" aria-hidden="true">
                <span class="text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_section') }}</span>
                <span class="text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_workflow') }}</span>
                <span class="text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_status') }}</span>
                <span class="text-right text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_change') }}</span>
            </div>
            @foreach (range(1, 4) as $placeholder)
                <div class="wf-grid wf-row" aria-hidden="true">
                    <span class="wf-c1"><span class="block h-3.5 w-[44%] rounded bg-surface-hover"></span><span class="mt-1.5 block h-2.5 w-[62%] rounded bg-surface-hover"></span></span>
                    <span class="wf-c2"><span class="block h-3.5 w-4/5 rounded bg-surface-hover"></span></span>
                    <span class="wf-c3"><span class="block h-5.5 w-26 rounded-full bg-surface-hover"></span></span>
                    <span class="wf-c4"><span class="block h-8.5 w-full rounded bg-surface-hover"></span></span>
                </div>
            @endforeach
            <p class="m-0 border-t border-border-soft px-4 py-3 text-caption text-text-muted">{{ __('admin.wf.loading_list') }}</p>
        </div>

        <div class="flex flex-col gap-4" wire:loading.remove wire:target="$refresh">
            {{-- A request that failed: the staged changes are kept, "Thử lại" sends it again --}}
            <div wire:ignore.self role="alert" x-show="failed" style="display: none"
                 class="flex flex-col items-start gap-3 rounded-md border border-danger-text/35 bg-surface p-5 shadow-card">
                <div class="flex items-center gap-2.5">
                    <x-bs-admin::icon name="ri-error-warning" class="size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))] shrink-0 text-danger-text" />
                    <h2 class="m-0 text-body font-semibold text-text-strong">{{ __('admin.wf.error_title') }}</h2>
                </div>
                <p class="m-0 max-w-140 text-small leading-relaxed text-text-muted">{{ __('admin.wf.error_body') }}</p>
                <x-bs-admin::button color="primary" x-on:click="doRetry()">
                    <x-bs-admin::icon name="ri-refresh" :class="$glyph" />
                    <span>{{ __('admin.wf.retry') }}</span>
                </x-bs-admin::button>
            </div>

            @if ($rows === [])
                <div wire:ignore.self x-show="! failed" class="rounded-md border border-border bg-surface p-6 shadow-card">
                    <p class="m-0 text-center text-small text-text-muted">{{ __('admin.wf.no_sections') }}</p>
                </div>
            @else
                {{-- First run: no section has a flow yet --}}
                <div wire:ignore.self x-show="! failed && ! order.some((k) => saved[k]?.template) && ! fromEmpty" {!! $hidden($hasAnyWorkflow) !!}
                     class="flex flex-col items-start gap-3.5 rounded-md border border-border bg-surface px-6 py-7 shadow-card">
                    <span class="inline-flex size-11.5 items-center justify-center rounded-full bg-primary-soft text-primary-text">
                        <x-bs-admin::icon name="ri-flow-chart" class="size-[min(1.5rem,calc(1.375rem+var(--user-text-boost)))]" />
                    </span>
                    <h2 class="m-0 text-lead font-semibold text-text-strong">{{ __('admin.wf.empty_title') }}</h2>
                    <p class="m-0 max-w-160 text-small leading-relaxed text-text-muted">{{ __('admin.wf.empty_body') }}</p>
                    <x-bs-admin::button color="primary" x-on:click="startFirst()">
                        <x-bs-admin::icon name="ri-add" :class="$glyph" />
                        <span>{{ __('admin.wf.empty_cta') }}</span>
                    </x-bs-admin::button>
                </div>

                <div wire:ignore.self x-show="! failed && (order.some((k) => saved[k]?.template) || fromEmpty)" {!! $hidden(! $hasAnyWorkflow) !!} class="flex flex-col gap-4">
                    <section aria-labelledby="wf-list-title" class="rounded-md border border-border bg-surface shadow-card">
                        {{-- Card head: the site, the title, the plan, the summary (one title + one line below 600) --}}
                        <div class="wf-cardhead-wide border-b border-border px-4 py-3">
                            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2">
                                <p class="m-0 flex min-w-0 flex-[1_1_100%] items-start gap-1.5 text-small leading-normal text-text-muted [overflow-wrap:anywhere]">
                                    <x-bs-admin::icon name="ri-global" class="mt-px {{ $glyph }}" />
                                    <span>{{ __('admin.wf.editing_site') }} <b class="font-semibold text-text-strong">{{ $site['name'] }}</b></span>
                                </p>
                                <h2 id="wf-list-title" class="m-0 text-body font-semibold text-text-strong">{{ __('admin.wf.list_title') }}</h2>
                                <x-bs-admin::badge color="primary" soft pill>
                                    <x-bs-admin::icon name="ri-vip-crown-2" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ $planLine }}
                                </x-bs-admin::badge>
                                <span wire:ignore class="text-caption text-text-muted min-[37.5rem]:ml-auto" x-text="summary(false)">{{ __('admin.wf.summary', ['on' => $onCount, 'total' => $total]) }}</span>
                            </div>
                        </div>
                        <div class="wf-cardhead-narrow border-b border-border px-3 py-2">
                            <p class="m-0 text-[calc(0.9375rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.wf.list_title_short') }}</p>
                            <p class="m-0 mt-0.5 text-small leading-snug text-text-muted [overflow-wrap:anywhere]">
                                <span>{{ $site['name'] }}</span>
                                <span aria-hidden="true"> · </span>
                                <x-bs-admin::badge color="primary" soft pill class="align-baseline">
                                    <x-bs-admin::icon name="ri-vip-crown-2" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ $planLine }}
                                </x-bs-admin::badge>
                                <span aria-hidden="true"> · </span>
                                <span wire:ignore x-text="summary(true)">{{ __('admin.wf.summary_short', ['on' => $onCount, 'total' => $total]) }}</span>
                            </p>
                        </div>

                        <div>
                            <div class="wf-grid wf-head" aria-hidden="true">
                                <span class="text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_section') }}</span>
                                <span class="text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_workflow') }}</span>
                                <span class="text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_status') }}</span>
                                <span class="text-right text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.col_change') }}</span>
                            </div>

                            @foreach ($rows as $row)
                                @php
                                    $k = Js::from($row['key']);
                                    $has = $row['template'] !== null;
                                    $stateWord = __($row['active'] ? 'admin.wf.enabled' : 'admin.wf.disabled');
                                @endphp
                                <div class="wf-grid wf-row" wire:key="wf-row-{{ $row['key'] }}">
                                    <span class="wf-c1">
                                        <span class="wf-name flex min-w-0 items-center gap-2 text-[calc(0.84375rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">
                                            <x-bs-admin::icon :name="$row['icon'] ?? 'newspaper'" class="{{ $glyph }} text-text-muted" />
                                            <span class="min-w-0">{{ $row['label'] }}</span>
                                        </span>
                                        <span class="wf-sub flex flex-wrap items-center gap-x-2 text-caption leading-normal text-text-muted">
                                            <span>{{ __('admin.wf.posts_writers', ['posts' => $num($row['total']), 'writers' => $num($row['writers'])]) }}</span>
                                            @if ($row['pending'] > 0)
                                                <span aria-hidden="true">·</span>
                                                <a href="{{ route('admin.entries.review', ['type' => $row['key']]) }}" wire:navigate
                                                   title="{{ __('admin.wf.pending_title', ['count' => $row['pending'], 'name' => $row['label']]) }}"
                                                   class="inline-flex min-h-tap-cell items-center gap-1 font-semibold whitespace-nowrap text-warning-text hover:underline any-pointer-coarse:min-h-hit">
                                                    <x-bs-admin::icon name="ri-time" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.wf.pending_line', ['count' => $num($row['pending'])]) }}
                                                </a>
                                            @endif
                                        </span>
                                    </span>

                                    <span class="wf-c2">
                                        <span wire:ignore.self class="wf-flow" x-show="row({{ $k }}).template" {!! $hidden(! $has) !!}>
                                            <span class="flex items-center gap-2 text-[calc(0.78125rem+var(--user-text-boost))] font-medium leading-snug text-text">
                                                <x-bs-admin::icon name="ri-flow-chart" class="{{ $smallGlyph }} text-primary-text" />
                                                <span class="min-w-0 [overflow-wrap:anywhere]"><span class="wf-narrow">{{ __('admin.wf.wf_prefix') }}&nbsp;</span><span wire:ignore x-text="name(row({{ $k }}).template)">{{ $has ? ($names[$row['template']] ?? $row['workflow_name']) : '' }}</span></span>
                                            </span>
                                        </span>
                                        <span wire:ignore.self class="wf-view" x-show="row({{ $k }}).template" {!! $hidden(! $has) !!}>
                                            <button type="button" x-on:click="openPick({{ $k }})"
                                                    class="inline-flex min-h-tap-cell items-center text-left text-caption font-semibold text-primary-text hover:underline any-pointer-coarse:min-h-hit">{{ __('admin.wf.view_steps') }}</button>
                                        </span>
                                        <span wire:ignore.self class="wf-none text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted" x-show="! row({{ $k }}).template" {!! $hidden($has) !!}>{{ __('admin.wf.none') }}</span>
                                    </span>

                                    <span class="wf-c3">
                                        <button wire:ignore.self type="button" role="switch" class="switch-row"
                                                x-show="row({{ $k }}).template" {!! $hidden(! $has) !!}
                                                aria-checked="{{ $row['active'] ? 'true' : 'false' }}" x-bind:aria-checked="row({{ $k }}).active ? 'true' : 'false'"
                                                aria-label="{{ __('admin.wf.switch_label', ['name' => $row['label'], 'state' => $stateWord]) }}" x-bind:aria-label="switchLabel({{ $k }})"
                                                title="{{ __($row['active'] ? 'admin.wf.toggle_off_title' : 'admin.wf.toggle_on_title', ['name' => $row['label']]) }}" x-bind:title="toggleTitle({{ $k }})"
                                                x-on:click="toggle({{ $k }})">
                                            <span wire:ignore class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold whitespace-nowrap"
                                                  x-bind:class="row({{ $k }}).active ? 'text-primary-text' : 'text-text-muted'"
                                                  x-text="row({{ $k }}).active ? @js(__('admin.wf.enabled')) : @js(__('admin.wf.disabled'))">{{ $stateWord }}</span>
                                        </button>
                                        <span wire:ignore.self class="flex min-h-hit items-center text-caption text-text-muted" x-show="! row({{ $k }}).template" {!! $hidden($has) !!}>{{ __('admin.wf.not_on') }}</span>
                                    </span>

                                    <span class="wf-c4">
                                        <span wire:ignore.self class="wf-narrow" x-show="row({{ $k }}).template" {!! $hidden(! $has) !!}>
                                            <x-bs-admin::button variant="outline" color="secondary" size="sm" x-on:click="openPick({{ $k }})">{{ __('admin.wf.view_steps_short') }}</x-bs-admin::button>
                                        </span>
                                        <x-bs-admin::button variant="outline" color="secondary" size="sm" class="wf-change" x-on:click="openPick({{ $k }})">
                                            <x-bs-admin::icon wire:ignore.self name="ri-add" class="{{ $smallGlyph }}" x-show="! row({{ $k }}).template" :style="$has ? 'display: none' : null" />
                                            <x-bs-admin::icon wire:ignore.self name="ri-repeat-2" class="{{ $smallGlyph }}" x-show="row({{ $k }}).template" :style="$has ? null : 'display: none'" />
                                            <span wire:ignore x-text="row({{ $k }}).template ? @js(__('admin.wf.change')) : @js(__('admin.wf.choose'))">{{ $has ? __('admin.wf.change') : __('admin.wf.choose') }}</span>
                                        </x-bs-admin::button>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                    <p class="m-0 text-caption leading-relaxed text-text-muted">{{ __('admin.wf.footer_note') }}</p>
                </div>
            @endif
        </div>

        {{-- Staged changes: discard or save them all at once --}}
        <div wire:ignore.self x-show="dirtyCount() > 0" style="display: none"
             class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-md border border-border bg-surface px-4 py-3 shadow-card">
            <p class="m-0 flex items-center gap-1.5 text-small font-semibold text-warning-text">
                <x-bs-admin::icon name="ri-error-warning" :class="$glyph" />{{ __('admin.wf.dirty') }}
            </p>
            <span class="ml-auto flex flex-wrap gap-2 max-[37.5rem]:w-full max-[37.5rem]:*:flex-[1_1_8.75rem]">
                <x-bs-admin::button wire:ignore.self variant="outline" color="secondary" x-on:click="discard()" x-bind:disabled="busy">{{ __('admin.wf.discard') }}</x-bs-admin::button>
                <x-bs-admin::button wire:ignore.self color="primary" x-on:click="save()" x-bind:disabled="busy" x-bind:aria-busy="busy ? 'true' : 'false'">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" />
                    <span>{{ __('admin.wf.save') }}</span>
                </x-bs-admin::button>
            </span>
        </div>
    </div>

    @if ($rows !== [])
        {{-- Picker: none or a template, with its step map. A bottom sheet below 600px. --}}
        <div wire:ignore.self x-show="pick !== null" style="display: none" class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
            <div class="absolute inset-0 bg-gray-900/45" x-on:click="closePick()"></div>
            <div wire:ignore.self x-show="pick !== null" x-trap="pick !== null"
                 role="dialog" aria-modal="true" aria-labelledby="wf-pick-title"
                 class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] min-[600px]:max-w-190 min-[600px]:rounded-md">
                <div class="flex items-center gap-2.5 border-b border-border px-4 py-2">
                    <x-bs-admin::icon name="ri-flow-chart" class="size-[min(1.5rem,calc(1.0625rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
                    <h2 wire:ignore id="wf-pick-title" class="m-0 min-w-0 flex-1 text-body font-semibold text-text-strong [overflow-wrap:anywhere]" x-text="pickTitle()">{{ __('admin.wf.pick_title', ['name' => $rows[0]['label']]) }}</h2>
                    <button type="button" class="hit-area flex size-control shrink-0 items-center justify-center rounded text-text-muted hover:bg-surface-hover" x-on:click="closePick()" aria-label="{{ __('admin.close') }}" title="{{ __('admin.close') }}">
                        <x-bs-admin::icon name="ri-close" class="size-5" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-3.5 overflow-auto p-4">
                    <p class="m-0 text-small leading-relaxed text-text-muted">{{ __('admin.wf.pick_hint') }}</p>

                    <div class="flex flex-col gap-2" role="group" aria-labelledby="wf-pick-title">
                        <button wire:ignore.self type="button" x-on:click="choose('none')" x-bind:aria-pressed="sel === 'none' ? 'true' : 'false'" aria-pressed="false"
                                class="flex w-full items-start gap-2.5 rounded-md border p-3 text-left any-pointer-coarse:min-h-hit"
                                x-bind:class="sel === 'none' ? 'border-primary-text bg-primary-soft ring-3 ring-primary-text/15' : 'border-border-strong bg-surface hover:bg-surface-muted'">
                            <span wire:ignore.self class="mt-0.5 inline-flex size-4.5 shrink-0 items-center justify-center rounded-full border-[1.5px]"
                                  x-bind:class="sel === 'none' ? 'border-primary-text' : 'border-border-input'" aria-hidden="true">
                                <span wire:ignore.self class="size-2.5 rounded-full bg-primary-text" x-show="sel === 'none'" style="display: none"></span>
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-small font-semibold text-text-strong">{{ __('admin.wf.none_option') }}</span>
                                    <x-bs-admin::badge color="secondary" soft pill>{{ __('admin.wf.none_steps') }}</x-bs-admin::badge>
                                </span>
                                <span class="text-caption leading-relaxed text-text-muted">{{ __('admin.wf.none_desc') }}</span>
                            </span>
                        </button>

                        @foreach ($templates as $tpl)
                            @php $tk = Js::from($tpl['key']); @endphp
                            @if ($tpl['locked'])
                                <button type="button" x-on:click="choose({{ $tk }})" aria-haspopup="dialog"
                                        class="flex w-full items-start gap-2.5 rounded-md border border-border-strong bg-surface p-3 text-left hover:bg-surface-muted any-pointer-coarse:min-h-hit">
                                    <span class="mt-0.5 w-4.5 shrink-0" aria-hidden="true"><x-bs-admin::icon name="ri-lock-2" class="{{ $smallGlyph }} text-text-muted" /></span>
                                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-small font-semibold text-text-strong">{{ $tpl['name'] }}</span>
                                            <x-bs-admin::badge color="primary" soft pill>
                                                <x-bs-admin::icon name="ri-vip-crown-2" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ $tpl['need'] !== null ? __('admin.wf.plan_line', ['plan' => $tpl['need']]) : __('admin.wf.locked') }}
                                            </x-bs-admin::badge>
                                        </span>
                                        <span class="text-caption leading-relaxed text-text-muted">{{ $tpl['description'] }}</span>
                                    </span>
                                </button>
                            @else
                                <button wire:ignore.self type="button" x-on:click="choose({{ $tk }})" x-bind:aria-pressed="sel === {{ $tk }} ? 'true' : 'false'" aria-pressed="false"
                                        class="flex w-full items-start gap-2.5 rounded-md border p-3 text-left any-pointer-coarse:min-h-hit"
                                        x-bind:class="sel === {{ $tk }} ? 'border-primary-text bg-primary-soft ring-3 ring-primary-text/15' : 'border-border-strong bg-surface hover:bg-surface-muted'">
                                    <span wire:ignore.self class="mt-0.5 inline-flex size-4.5 shrink-0 items-center justify-center rounded-full border-[1.5px]"
                                          x-bind:class="sel === {{ $tk }} ? 'border-primary-text' : 'border-border-input'" aria-hidden="true">
                                        <span wire:ignore.self class="size-2.5 rounded-full bg-primary-text" x-show="sel === {{ $tk }}" style="display: none"></span>
                                    </span>
                                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-small font-semibold text-text-strong">{{ $tpl['name'] }}</span>
                                            <x-bs-admin::badge color="secondary" soft pill>{{ __('admin.wf.steps_count', ['count' => count($tpl['states'])]) }}</x-bs-admin::badge>
                                        </span>
                                        <span class="text-caption leading-relaxed text-text-muted">{{ $tpl['description'] }}</span>
                                    </span>
                                </button>
                            @endif
                        @endforeach
                    </div>

                    <div wire:ignore.self x-show="sel === 'none'" style="display: none"
                         class="flex items-start gap-2.5 rounded bg-surface-hover px-3.5 py-3 text-small leading-relaxed text-text-muted">
                        <x-bs-admin::icon name="ri-information" :class="$glyph" />
                        <span>{{ __('admin.wf.none_info') }}</span>
                    </div>

                    @foreach ($templates as $tpl)
                        @continue($tpl['locked'])
                        @php $tk = Js::from($tpl['key']); @endphp
                        <div wire:ignore.self x-show="sel === {{ $tk }}" style="display: none"
                             class="@container/wfp flex flex-col gap-3 rounded-md border border-border bg-surface-muted p-3.5">
                            <h3 class="m-0 text-caption font-bold tracking-wider text-text-muted uppercase">
                                {{ __('admin.wf.steps_title', ['name' => $tpl['name']]) }}
                                <span class="font-medium tracking-normal normal-case">· {{ __('admin.wf.steps_count', ['count' => count($tpl['states'])]) }}</span>
                            </h3>

                            {{-- The steps as a track with icons; as wrapping pills in a narrow box --}}
                            <ol class="m-0 flex w-full list-none items-start p-0 @max-[40rem]/wfp:hidden">
                                @foreach ($tpl['states'] as $step)
                                    <li @class(['flex min-w-0 flex-1 flex-col items-center gap-1.5'])>
                                        <span class="flex w-full items-center" aria-hidden="true">
                                            <span @class(['h-0.5 flex-1 bg-border-strong', 'invisible' => $loop->first])></span>
                                            <span @class([
                                                'inline-flex size-6.5 shrink-0 items-center justify-center rounded-full border-2',
                                                'border-success-text bg-success-soft text-success-text' => $step['kind'] === 'published',
                                                'border-primary-text/50 bg-surface text-primary-text' => $step['kind'] === 'initial',
                                                'border-border-strong bg-surface text-text-muted' => ! in_array($step['kind'], ['published', 'initial'], true),
                                            ])><x-bs-admin::icon :name="$kindIcons[$step['kind']]" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))]" /></span>
                                            <span @class(['h-0.5 flex-1 bg-border-strong', 'invisible' => $loop->last])></span>
                                        </span>
                                        <span @class([
                                            'px-1 text-center text-caption leading-snug [text-wrap:pretty]',
                                            'font-semibold text-success-text' => $step['kind'] === 'published',
                                            'text-text-muted' => $step['kind'] !== 'published',
                                        ])>{{ $step['label'] }}</span>
                                    </li>
                                @endforeach
                            </ol>
                            <ol class="m-0 hidden list-none flex-wrap items-center gap-2 p-0 @max-[40rem]/wfp:flex">
                                @foreach ($tpl['states'] as $step)
                                    <li @class([
                                        'inline-flex items-center gap-1.5 rounded-full border px-2.75 py-1.25 text-caption leading-snug',
                                        'border-success-text/40 bg-success-soft font-semibold text-success-text' => $step['kind'] === 'published',
                                        'border-primary-text/30 bg-primary-soft font-medium text-text' => $step['kind'] === 'initial',
                                        'border-border-strong bg-surface font-medium text-text' => ! in_array($step['kind'], ['published', 'initial'], true),
                                    ])><x-bs-admin::icon :name="$kindIcons[$step['kind']]" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" />{{ $step['label'] }}</li>
                                @endforeach
                            </ol>
                            <p class="m-0 flex items-start gap-1.5 text-caption leading-relaxed text-text-muted">
                                <x-bs-admin::icon name="ri-global" class="mt-0.5 size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 text-success-text" />
                                <span>{{ __('admin.wf.legend') }}</span>
                            </p>
                            <div class="h-px bg-border" aria-hidden="true"></div>
                            <h3 class="m-0 text-caption font-bold tracking-wider text-text-muted uppercase">{{ __('admin.wf.moves_title') }}</h3>

                            @foreach (['forward' => ['ri-arrow-right-circle', 'admin.wf.forward_group'], 'back' => ['ri-arrow-go-back', 'admin.wf.return_group']] as $group => [$groupIcon, $groupLabel])
                                @continue($tpl[$group] === [])
                                <h4 class="m-0 flex items-center gap-1.5 text-caption font-bold tracking-wide text-text-muted uppercase">
                                    <x-bs-admin::icon :name="$groupIcon" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" />{{ __($groupLabel) }}
                                </h4>
                                <ul class="m-0 flex list-none flex-col gap-1.75 p-0">
                                    @foreach ($tpl[$group] as $move)
                                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 rounded border border-border bg-surface px-2.75 py-2.25 @max-[40rem]/wfp:grid-cols-1">
                                            <span class="flex min-w-0 flex-col gap-0.5">
                                                <span class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ $move['label'] }}</span>
                                                <span class="text-caption leading-normal text-text-muted">{{ $move['flow'] }}</span>
                                            </span>
                                            <span class="flex flex-wrap items-center justify-end gap-1.5 @max-[40rem]/wfp:justify-start">
                                                <span title="{{ __('admin.wf.perm_title') }}" class="inline-flex items-center rounded-full bg-surface-hover px-2 py-1 font-mono text-caption leading-none font-semibold text-text-muted">{{ $move['permission'] }}</span>
                                                @if ($move['note'])
                                                    <x-bs-admin::badge color="warning" soft pill>
                                                        <x-bs-admin::icon name="ri-edit-2" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.wf.note_required') }}
                                                    </x-bs-admin::badge>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-surface-muted px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <p wire:ignore class="m-0 mr-auto text-caption text-text-muted" x-show="applyWhy() !== ''" x-text="applyWhy()" aria-live="polite">{{ __('admin.wf.apply_why_same') }}</p>
                    <span class="flex flex-wrap gap-2 max-[599.98px]:w-full max-[599.98px]:*:flex-[1_1_8.75rem]">
                        <x-bs-admin::button variant="outline" color="secondary" x-on:click="closePick()">{{ __('admin.cancel') }}</x-bs-admin::button>
                        <x-bs-admin::button wire:ignore.self color="primary" x-on:click="apply()" x-bind:disabled="applyWhy() !== ''" title="{{ __('admin.wf.apply_go_title') }}">
                            <x-bs-admin::icon name="ri-check" :class="$glyph" />
                            <span wire:ignore x-text="applyLabel()">{{ __('admin.wf.apply') }}</span>
                        </x-bs-admin::button>
                    </span>
                </div>
            </div>
        </div>

        {{-- Plan dialog for a locked template --}}
        @foreach ($templates as $tpl)
            @continue(! $tpl['locked'])
            @php $tk = Js::from($tpl['key']); $upId = 'wf-up-title-'.$tpl['key']; @endphp
            <div wire:ignore.self x-show="up === {{ $tk }}" style="display: none" class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
                <div class="absolute inset-0 bg-gray-900/45" x-on:click="up = null"></div>
                <div wire:ignore.self x-show="up === {{ $tk }}" x-trap="up === {{ $tk }}"
                     role="dialog" aria-modal="true" aria-labelledby="{{ $upId }}"
                     class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] min-[600px]:max-w-110 min-[600px]:rounded-md">
                    <div class="flex min-h-0 flex-1 flex-col gap-3.5 overflow-auto px-5 pt-5.5 pb-1.5">
                        <div class="flex items-center gap-2.5">
                            <x-bs-admin::icon name="ri-vip-crown-2" class="size-[min(1.5rem,calc(1.375rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
                            <h2 id="{{ $upId }}" class="m-0 text-lead font-semibold text-text-strong [overflow-wrap:anywhere]">{{ __('admin.wf.up_title', ['name' => $tpl['name']]) }}</h2>
                        </div>
                        <p class="m-0 text-small leading-relaxed text-text-muted">
                            {{ __('admin.wf.up_intro') }}
                            {{ $site['plan'] !== null ? __('admin.wf.up_current', ['plan' => $site['plan']]) : __('admin.wf.up_current_none') }}
                            {{ $tpl['need'] !== null ? __('admin.wf.up_need', ['plan' => $tpl['need']]) : __('admin.wf.up_need_none') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2 border-t border-border bg-surface-muted px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599.98px]:*:flex-[1_1_8.75rem]">
                        <x-bs-admin::button variant="outline" color="secondary" x-on:click="up = null">{{ __('admin.wf.up_later') }}</x-bs-admin::button>
                        @if ($upgradeUrl !== null && $tpl['need'] !== null)
                            <x-bs-admin::button color="primary" :href="$upgradeUrl" :navigate="false">
                                <x-bs-admin::icon name="ri-arrow-right-up" :class="$glyph" />
                                <span>{{ __('admin.wf.up_go', ['plan' => $tpl['need']]) }}</span>
                            </x-bs-admin::button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    {{-- A question before a pick or a switch that moves waiting posts --}}
    <div wire:ignore.self x-show="confirm !== null" style="display: none" class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
        <div class="absolute inset-0 bg-gray-900/45" x-on:click="confirm = null"></div>
        <div wire:ignore.self x-show="confirm !== null" x-trap="confirm !== null"
             role="alertdialog" aria-modal="true" aria-labelledby="wf-cf-title" aria-describedby="wf-cf-text"
             class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] min-[600px]:max-w-110 min-[600px]:rounded-md">
            <div class="flex min-h-0 flex-1 flex-col items-center gap-3.5 overflow-auto px-5 pt-6 pb-2 text-center">
                <span class="inline-flex size-14 items-center justify-center rounded-full bg-warning-soft text-warning-text" aria-hidden="true">
                    <x-bs-admin::icon name="ri-alert" class="size-[min(1.75rem,calc(1.625rem+var(--user-text-boost)))]" />
                </span>
                <h2 wire:ignore id="wf-cf-title" class="m-0 text-[calc(1.0625rem+var(--user-text-boost))] font-semibold text-text-strong [overflow-wrap:anywhere]" x-text="confirm?.title ?? ''"></h2>
                <p wire:ignore id="wf-cf-text" class="m-0 text-small leading-relaxed text-text-muted" x-text="confirm?.text ?? ''"></p>
            </div>
            <div class="flex flex-wrap justify-center gap-2 border-t border-border bg-surface-muted px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599.98px]:*:flex-[1_1_8.75rem]">
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="confirm = null">{{ __('admin.wf.cf_keep') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" x-on:click="confirmOk()"><span wire:ignore x-text="confirm?.ok ?? ''">{{ __('admin.wf.turn_off') }}</span></x-bs-admin::button>
            </div>
        </div>
    </div>

    {{-- Leaving with staged changes (links inside the admin open in place) --}}
    <div wire:ignore.self x-show="leave !== null" style="display: none" class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
        <div class="absolute inset-0 bg-gray-900/45" x-on:click="leave = null"></div>
        <div wire:ignore.self x-show="leave !== null" x-trap="leave !== null"
             role="alertdialog" aria-modal="true" aria-labelledby="wf-leave-title" aria-describedby="wf-leave-text"
             class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] min-[600px]:max-w-110 min-[600px]:rounded-md">
            <div class="flex min-h-0 flex-1 flex-col items-center gap-3.5 overflow-auto px-5 pt-6 pb-2 text-center">
                <span class="inline-flex size-14 items-center justify-center rounded-full bg-warning-soft text-warning-text" aria-hidden="true">
                    <x-bs-admin::icon name="ri-alert" class="size-[min(1.75rem,calc(1.625rem+var(--user-text-boost)))]" />
                </span>
                <h2 id="wf-leave-title" class="m-0 text-[calc(1.0625rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.wf.leave_title') }}</h2>
                <p wire:ignore id="wf-leave-text" class="m-0 text-small leading-relaxed text-text-muted" x-text="leaveText()"></p>
            </div>
            <div class="flex flex-wrap justify-center gap-2 border-t border-border bg-surface-muted px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599.98px]:*:flex-[1_1_8.75rem]">
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="leave = null">{{ __('admin.wf.leave_stay') }}</x-bs-admin::button>
                <x-bs-admin::button color="danger" x-on:click="leaveNow()">{{ __('admin.wf.leave_ok') }}</x-bs-admin::button>
            </div>
        </div>
    </div>

    <x-bs-admin::toast :message="$toast" dismiss="dismissToast" :seq="$toastSeq" />
</div>
