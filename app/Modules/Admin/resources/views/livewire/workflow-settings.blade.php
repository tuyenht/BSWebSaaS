<x-admin::settings-shell active="workflow">
    @if (session('status'))
        <x-admin::alert color="success" icon="shield">{{ session('status') }}</x-admin::alert>
    @endif
    @error('template')
        <x-admin::alert color="danger" icon="ri-error-warning">{{ $message }}</x-admin::alert>
    @enderror

    <div
        x-data="{
            open: false,
            row: null,
            selected: null,
            templates: @js(collect($templates)->map(fn ($t, $k) => [
                'key' => $k,
                'name' => $t['name'],
                'locked' => $k === 'editorial' && $editorialLocked,
                'states' => $t['states'],
                'transitions' => $t['transitions'],
            ])->values()->all()),
            openFor(row) { this.row = row; this.selected = row.template ?? null; this.open = true; },
            tpl() { return this.templates.find(t => t.key === this.selected) ?? null; },
            pick(t) { if (! t.locked) this.selected = t.key; },
            stepLabel(key) { const s = (this.tpl()?.states ?? []).find(x => x.key === key); return s ? s.label : key; },
            submit(activate) {
                const doApply = () => { $wire.apply(this.row.key, this.selected, activate); this.open = false; };
                if (this.row.template && this.row.template !== this.selected && this.row.pending > 0) {
                    $bsConfirm({
                        icon: 'warning',
                        text: @js(__('admin.wf.swap_confirm')).replace(':count', this.row.pending),
                        confirm: @js(__('admin.wf.swap_go')),
                        cancel: @js(__('admin.cancel')),
                    }).then(ok => ok && doApply());
                } else { doApply(); }
            },
        }"
        x-on:keydown.escape.window="open = false"
        class="space-y-6"
    >
        @if (! $hasAnyWorkflow && $rows !== [])
            {{-- First-run explainer: what an approval flow is, one primary CTA (no dead-end). --}}
            <x-admin::card>
                <div class="flex flex-col items-start gap-3 py-2 sm:flex-row sm:items-center">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-admin-primary/10 text-admin-primary">
                        <x-admin::icon name="ri-flow-chart" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-heading">{{ __('admin.wf.empty_title') }}</p>
                        <p class="mt-0.5 text-sm text-body-muted">{{ __('admin.wf.empty_body') }}</p>
                    </div>
                    <x-admin::button color="primary" x-on:click="openFor(@js($rows[0]))">
                        {{ __('admin.wf.empty_cta') }}
                    </x-admin::button>
                </div>
            </x-admin::card>
        @endif

        <x-admin::card :title="__('admin.workflow_settings_title')">
            <p class="mb-4 text-sm text-body-faint">{{ __('admin.wf.subtitle') }}</p>

            @if ($rows === [])
                <p class="py-6 text-center text-sm text-body-muted">{{ __('admin.wf.no_sections') }}</p>
            @else
                <ul class="divide-y divide-border-soft">
                    @foreach ($rows as $row)
                        <li class="flex flex-col gap-3 py-3.5 md:flex-row md:items-center" wire:key="wf-row-{{ $row['key'] }}">
                            {{-- Section name + volume --}}
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded bg-surface-hover text-body-muted">
                                    <x-admin::icon :name="$row['icon'] ?? 'newspaper'" class="size-4.5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-heading">{{ $row['label'] }}</p>
                                    <p class="text-xs text-body-faint">{{ __('admin.wf.posts_count', ['count' => $row['total']]) }}</p>
                                </div>
                            </div>

                            {{-- Current workflow + status --}}
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                @if ($row['template'] !== null)
                                    <div class="min-w-0">
                                        <p class="truncate text-sm text-body-text">{{ $row['workflow_name'] }}</p>
                                        <button type="button" class="text-xs font-medium text-admin-primary hover:underline"
                                                x-on:click="openFor(@js($row))">{{ __('admin.wf.view_steps') }}</button>
                                    </div>
                                    @if ($row['active'])
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-admin-success/10 px-2.5 py-0.5 text-[11px] font-bold text-[#0a6d60]">
                                            <x-admin::icon name="ri-checkbox-circle" class="size-3.5" />{{ __('admin.wf.enabled') }}
                                        </span>
                                    @else
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-surface-hover px-2.5 py-0.5 text-[11px] font-bold text-body-muted">
                                            <x-admin::icon name="ri-pause-circle" class="size-3.5" />{{ __('admin.wf.disabled') }}
                                        </span>
                                    @endif
                                    @if ($row['pending'] > 0)
                                        <span class="shrink-0 rounded-full bg-admin-warning/15 px-2.5 py-0.5 text-[11px] font-bold text-[#8a5f18]">
                                            {{ __('admin.wf.pending_count', ['count' => $row['pending']]) }}
                                        </span>
                                    @endif
                                @else
                                    <p class="text-sm text-body-faint">{{ __('admin.wf.none') }}</p>
                                @endif
                            </div>

                            {{-- Actions --}}
                            <div class="flex shrink-0 items-center gap-2">
                                <x-admin::button color="light" size="sm" x-on:click="openFor(@js($row))">
                                    <x-admin::icon :name="$row['template'] ? 'ri-repeat-2' : 'ri-add'" class="size-4" />
                                    {{ $row['template'] ? __('admin.wf.change') : __('admin.wf.choose') }}
                                </x-admin::button>
                                @if ($row['template'] !== null)
                                    @if ($row['active'] && $row['pending'] > 0)
                                        <button type="button" role="switch" aria-checked="true"
                                                class="relative h-6 w-11 shrink-0 cursor-pointer rounded-full bg-admin-success transition-colors"
                                                title="{{ __('admin.wf.turn_off') }}"
                                                x-on:click="$bsConfirm({
                                                    icon: 'warning',
                                                    text: @js(__('admin.wf.turn_off_confirm')).replace(':count', {{ $row['pending'] }}),
                                                    confirm: @js(__('admin.wf.turn_off')),
                                                    cancel: @js(__('admin.cancel')),
                                                }).then(ok => ok && $wire.toggle(@js($row['key'])))">
                                            <span class="absolute right-0.5 top-0.5 size-5 rounded-full bg-white shadow"></span>
                                        </button>
                                    @else
                                        <button type="button" role="switch" aria-checked="{{ $row['active'] ? 'true' : 'false' }}"
                                                class="relative h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors {{ $row['active'] ? 'bg-admin-success' : 'bg-gray-300' }}"
                                                title="{{ $row['active'] ? __('admin.wf.turn_off') : __('admin.wf.turn_on') }}"
                                                wire:click="toggle(@js($row['key']))">
                                            <span class="absolute top-0.5 size-5 rounded-full bg-white shadow {{ $row['active'] ? 'right-0.5' : 'left-0.5' }}"></span>
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin::card>

        {{-- Template picker + step-map preview (design: "Xem sơ đồ các bước") --}}
        <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-gray-900/50" x-on:click="open = false"></div>
            <div class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-t-md bg-surface p-5 shadow-[0_5px_20px_rgba(56,65,74,.28)] sm:rounded-md">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-heading" x-text="row ? (@js(__('admin.wf.modal_title')) + ' — ' + row.label) : ''"></h2>
                        <p class="mt-0.5 text-sm text-body-muted">{{ __('admin.wf.modal_hint') }}</p>
                    </div>
                    <button type="button" class="flex size-9 items-center justify-center rounded text-body-muted hover:bg-surface-hover" x-on:click="open = false" aria-label="{{ __('admin.close') }}">
                        <x-admin::icon name="x" class="size-5" />
                    </button>
                </div>

                {{-- Template choices --}}
                <div class="grid gap-2 sm:grid-cols-2">
                    <template x-for="t in templates" :key="t.key">
                        <button type="button"
                                class="rounded border p-3 text-left transition-colors"
                                :class="{
                                    'border-admin-primary bg-admin-primary/5': selected === t.key && ! t.locked,
                                    'border-border hover:border-border-strong': selected !== t.key && ! t.locked,
                                    'cursor-not-allowed border-border opacity-60': t.locked,
                                }"
                                x-on:click="pick(t)">
                            <span class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-heading" x-text="t.name"></span>
                                <span x-show="t.locked" class="inline-flex items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-[10.5px] font-bold text-body-muted">
                                    <x-admin::icon name="lock" class="size-3" />{{ __('admin.wf.locked') }}
                                </span>
                            </span>
                            <span class="mt-1 block text-xs text-body-faint" x-text="t.states.length + ' {{ __('admin.wf.steps_unit') }}'"></span>
                        </button>
                    </template>
                </div>
                <p x-show="tpl() === null" class="mt-3 text-sm text-body-faint">{{ __('admin.wf.pick_hint') }}</p>
                <p x-show="templates.some(t => t.locked)" class="mt-2 text-xs text-body-faint">{{ __('admin.wf.locked_hint') }}</p>

                {{-- Step map --}}
                <template x-if="tpl()">
                    <div class="mt-5">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-placeholder">{{ __('admin.wf.steps_title') }}</p>
                        <ol class="flex flex-wrap items-center gap-1.5">
                            <template x-for="(s, i) in tpl().states" :key="s.key">
                                <li class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium"
                                          :class="s.is_published ? 'border-admin-success/40 bg-admin-success/10 text-[#0a6d60]' : 'border-border text-body-text'">
                                        <span x-text="s.label"></span>
                                        <span x-show="s.is_published" class="font-bold">· {{ __('admin.wf.public_step') }}</span>
                                    </span>
                                    <x-admin::icon x-show="i < tpl().states.length - 1" name="chevron-right" class="size-3.5 text-placeholder" />
                                </li>
                            </template>
                        </ol>
                        <p class="mt-2 text-xs text-body-faint">{{ __('admin.wf.public_step_hint') }}</p>

                        {{-- Transitions, grouped: forward vs return-with-note --}}
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-placeholder">{{ __('admin.wf.forward_group') }}</p>
                                <ul class="space-y-1.5">
                                    <template x-for="tr in tpl().transitions.filter(t => ! t.note)" :key="tr.key">
                                        <li class="rounded border border-border p-2 text-xs">
                                            <span class="font-medium text-body-text" x-text="tr.label"></span>
                                            <span class="block text-body-faint" x-text="stepLabel(tr.from) + ' → ' + stepLabel(tr.to)"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <div>
                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-placeholder">{{ __('admin.wf.return_group') }}</p>
                                <ul class="space-y-1.5">
                                    <template x-for="tr in tpl().transitions.filter(t => t.note)" :key="tr.key">
                                        <li class="rounded border border-admin-danger/30 p-2 text-xs">
                                            <span class="font-medium text-body-text" x-text="tr.label"></span>
                                            <span class="block text-body-faint" x-text="stepLabel(tr.from) + ' → ' + stepLabel(tr.to)"></span>
                                            <span class="mt-0.5 inline-block rounded-full bg-admin-warning/15 px-1.5 py-px text-[10px] font-bold text-[#8a5f18]">{{ __('admin.wf.note_required') }}</span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                    <x-admin::button color="light" x-on:click="open = false">{{ __('admin.cancel') }}</x-admin::button>
                    <x-admin::button color="light"
                                     x-bind:disabled="tpl() === null || tpl().locked || (row && row.template === selected)"
                                     x-on:click="submit(false)">{{ __('admin.wf.apply') }}</x-admin::button>
                    <x-admin::button color="primary"
                                     x-bind:disabled="tpl() === null || tpl().locked || (row && row.template === selected && row.active)"
                                     x-on:click="submit(true)">{{ __('admin.wf.apply_on') }}</x-admin::button>
                </div>
            </div>
        </div>
    </div>
</x-admin::settings-shell>
