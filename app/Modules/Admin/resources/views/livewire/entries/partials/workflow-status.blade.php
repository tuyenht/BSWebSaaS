{{--
    "Trạng thái duyệt" — design_handoff_workflow screen 2, ported 1:1 onto the admin tokens.
    Replaces the tier-1 Publish button when an active workflow governs the post. Every stage,
    label and button below comes from the workflow rows (data), never from a hard-coded status.

    Expects: $workflow (Editor::workflowPanel()), $trail / $trailCursor / $trailTotal (component state).
--}}
@php
    $stepIcon = fn (array $step): string => $step['is_published'] ? 'ri-global'
        : ($step['is_initial'] ? 'ri-draft' : ($step['is_terminal'] ? 'ri-archive' : 'ri-user-search'));
    $current = $workflow['steps'][$workflow['current']['index']];
    $stepCount = count($workflow['steps']);
    $progress = $stepCount > 1 ? (int) round($workflow['current']['index'] / ($stepCount - 1) * 100) : 100;
    $noteActions = array_values(array_filter($workflow['actions'], fn (array $a): bool => $a['note']));
@endphp

<div
    class="@container space-y-4"
    x-data="{
        modal: null,
        note: '',
        bad: false,
        open(key) { this.modal = key; this.note = ''; this.bad = false; $wire.set('transitionNote', '', false); $wire.set('assignTo', null, false); $nextTick(() => $refs['cancel-' + key]?.focus()); },
        close() { this.modal = null; this.bad = false; },
        submit(key) {
            if (! this.note.trim()) { this.bad = true; return; }
            $wire.set('transitionNote', this.note, false);
            $wire.applyTransition(key);
        },
    }"
    x-on:keydown.escape.window="modal && close()"
    x-on:workflow-moved.window="close()"
>
    @error('workflow')
        <x-admin::alert color="danger" icon="ri-error-warning">{{ $message }}</x-admin::alert>
    @enderror

    {{-- Card 1: stage + actions --}}
    <x-admin::card>
        <x-slot:header>
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <x-admin::icon name="ri-flow-chart" class="size-4 text-admin-primary" />
                <span class="text-sm font-semibold text-heading">{{ __('admin.wfb.title') }}</span>
                @if ($workflow['is_live'])
                    <span class="inline-flex h-[22px] items-center gap-1 rounded-full bg-admin-success/10 px-2.5 text-[10.5px] font-bold text-[#0a6d60]">
                        <x-admin::icon name="ri-global" class="size-3" />{{ __('admin.wfb.live_chip') }}
                    </span>
                @endif
                @if ($workflow['assignee'] !== null)
                    <span class="ml-auto inline-flex h-[22px] items-center gap-1 rounded-full bg-admin-primary/10 px-2.5 text-[10.5px] font-bold text-admin-primary">
                        <x-admin::icon name="ri-user-follow" class="size-3" />{{ __('admin.wfb.assigned_to', ['name' => $workflow['assignee']]) }}
                    </span>
                @endif
            </div>
        </x-slot:header>

        <div class="space-y-3.5">
            {{-- Step headline --}}
            <div class="flex flex-wrap items-baseline gap-2">
                <span class="inline-flex items-center gap-1 text-[12.5px] font-bold text-admin-primary">
                    <x-admin::icon :name="$stepIcon($current)" class="size-3.5" />
                    {{ __('admin.wfb.step_of', ['n' => $workflow['current']['index'] + 1, 'total' => $stepCount, 'label' => $workflow['current']['label']]) }}
                </span>
                <span class="inline-flex items-center gap-1 text-[11.5px] text-body-muted">
                    <x-admin::icon name="ri-flow-chart" class="size-3" />{{ $workflow['name'] }}
                </span>
            </div>

            {{-- Milestone track (≥ 560px container) --}}
            <ol class="hidden w-full items-start @[560px]:flex" aria-label="{{ __('admin.wfb.title') }}">
                @foreach ($workflow['steps'] as $i => $step)
                    @php
                        $done = $step['state'] === 'done';
                        $cur = $step['state'] === 'current';
                        $pub = $step['is_published'];
                    @endphp
                    <li class="flex min-w-0 flex-1 flex-col items-center gap-1.5" wire:key="wf-step-{{ $step['key'] }}" @if ($cur) aria-current="step" @endif>
                        <span class="flex w-full items-center">
                            <span class="h-0.5 flex-1 {{ $i === 0 ? 'invisible' : '' }} {{ $done || $cur ? 'bg-admin-primary' : 'bg-border' }}"></span>
                            <span @class([
                                'inline-flex size-[26px] shrink-0 items-center justify-center rounded-full border-2 text-[13px]',
                                'border-admin-primary bg-admin-primary text-white' => $done,
                                'border-admin-primary bg-surface text-admin-primary shadow-[0_0_0_4px_rgba(64,81,137,.14)]' => $cur && ! $pub,
                                'border-admin-success bg-surface text-[#0a6d60] shadow-[0_0_0_4px_rgba(10,179,156,.16)]' => $cur && $pub,
                                'border-admin-success/45 bg-surface text-[#0a6d60]' => ! $done && ! $cur && $pub,
                                'border-border bg-surface text-body-muted' => ! $done && ! $cur && ! $pub,
                            ])>
                                <x-admin::icon :name="$done ? 'ri-check' : $stepIcon($step)" class="size-3.5" />
                            </span>
                            <span class="h-0.5 flex-1 {{ $i === $stepCount - 1 ? 'invisible' : '' }} {{ $done ? 'bg-admin-primary' : 'bg-border' }}"></span>
                        </span>
                        <span @class([
                            'px-1 text-center text-[11.5px] leading-snug text-pretty',
                            'font-bold text-admin-primary' => $cur && ! $pub,
                            'font-bold text-[#0a6d60]' => $cur && $pub,
                            'text-body-text' => $done,
                            'text-body-muted' => ! $done && ! $cur,
                        ])>{{ $step['label'] }}</span>
                    </li>
                @endforeach
            </ol>

            {{-- Compact bar (narrow container) --}}
            <div class="flex w-full flex-col gap-1.5 @[560px]:hidden">
                <span class="flex h-1.5 overflow-hidden rounded-full bg-border-soft"><span class="block h-1.5 rounded-full bg-admin-primary" style="width: {{ $progress }}%"></span></span>
                <span class="text-[11.5px] text-body-muted">
                    {{ __('admin.wfb.step_of', ['n' => $workflow['current']['index'] + 1, 'total' => $stepCount, 'label' => $workflow['current']['label']]) }}
                    · {{ $workflow['next_label'] !== null ? __('admin.wfb.next_step', ['label' => $workflow['next_label']]) : __('admin.wfb.last_step') }}
                </span>
            </div>

            @if ($workflow['pre_workflow'])
                <p class="flex items-start gap-2 rounded bg-surface-hover px-3 py-2.5 text-[12.5px] leading-relaxed text-body-muted">
                    <x-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ __('admin.wfb.pre_workflow') }}</span>
                </p>
            @endif

            @if ($workflow['missing'] !== [] && $workflow['actions'] !== [] && ! $workflow['current']['is_published'])
                <p class="flex items-start gap-2 rounded bg-admin-warning/15 px-3 py-2.5 text-[12.5px] leading-relaxed text-[#8a5f18]">
                    <x-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ __('admin.wfb.missing_line', ['fields' => implode(', ', $workflow['missing'])]) }}</span>
                </p>
            @endif

            @if ($workflow['actions'] !== [])
                <div class="space-y-2">
                    <p class="text-[11px] font-bold uppercase tracking-[.06em] text-body-muted">{{ __('admin.wfb.actions_title') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($workflow['actions'] as $action)
                            @php
                                $title = ($action['note'] ? __('admin.wfb.tip_note').' ' : '')
                                    .__('admin.wfb.tip_move', ['label' => $action['to_label']])
                                    .($action['to_published'] ? ' '.__('admin.wfb.tip_public') : '')
                                    .($workflow['is_live'] && ! $action['to_published'] ? ' '.__('admin.wfb.tip_offline') : '');
                            @endphp
                            @if ($action['to_published'])
                                <x-admin::button color="primary" wire:click="applyTransition(@js($action['key']))" wire:loading.attr="disabled" title="{{ $title }}" wire:key="wf-action-{{ $action['key'] }}" class="@max-[560px]:w-full pointer-coarse:min-h-11">
                                    <x-admin::icon name="ri-check-double" class="size-4" />{{ $action['label'] }}
                                </x-admin::button>
                            @elseif ($action['note'])
                                <x-admin::button color="danger" variant="outline" x-on:click="open(@js($action['key']))" title="{{ $title }}" wire:key="wf-action-{{ $action['key'] }}" class="@max-[560px]:w-full pointer-coarse:min-h-11">
                                    <x-admin::icon name="ri-arrow-go-back" class="size-4" />{{ $action['label'] }}
                                </x-admin::button>
                            @else
                                <x-admin::button color="light" wire:click="applyTransition(@js($action['key']))" wire:loading.attr="disabled" title="{{ $title }}" wire:key="wf-action-{{ $action['key'] }}" class="@max-[560px]:w-full pointer-coarse:min-h-11">
                                    <x-admin::icon name="ri-send-plane" class="size-4" />{{ $action['label'] }}
                                </x-admin::button>
                            @endif
                        @endforeach
                    </div>
                    <p class="text-[11.5px] leading-relaxed text-body-muted">
                        {{ $workflow['next_label'] !== null ? __('admin.wfb.actions_hint_next', ['label' => $workflow['next_label']]).' ' : '' }}{{ __('admin.wfb.actions_hint_role') }}
                    </p>
                    @if ($workflow['is_live'])
                        {{-- No revisions yet: a saved edit to live content is live at once. Say so. --}}
                        <p class="flex items-start gap-2 rounded bg-surface-hover px-3 py-2.5 text-[12.5px] leading-relaxed text-body-muted">
                            <x-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" />
                            <span>{{ __('admin.wfb.live_edit_hint') }}</span>
                        </p>
                    @endif
                </div>
            @else
                <p class="flex items-start gap-2 rounded bg-admin-info/10 px-3 py-2.5 text-[12.5px] leading-relaxed text-[#22688f]">
                    <x-admin::icon name="ri-time" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ $workflow['current']['is_published'] ? __('admin.wfb.wait_line_live') : __('admin.wfb.wait_line') }}</span>
                </p>
            @endif
        </div>
    </x-admin::card>

    {{-- Card 2: trail --}}
    <x-admin::card>
        <x-slot:header>
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <x-admin::icon name="ri-history" class="size-4 text-body-muted" />
                <span class="text-sm font-semibold text-heading">{{ __('admin.wfb.trail_title') }}</span>
                <span class="ml-auto text-[11.5px] text-body-muted">{{ __('admin.wfb.trail_count', ['count' => $trailTotal]) }}</span>
            </div>
        </x-slot:header>

        @if ($trail === [])
            <p class="text-sm leading-relaxed text-body-faint">{{ __('admin.wfb.trail_empty') }}</p>
        @else
            <ol class="space-y-0">
                @foreach ($trail as $row)
                    @php
                        $who = $row['actor'] ?? __('admin.wfb.actor_gone');
                        $initial = mb_strtoupper(mb_substr((string) \Illuminate\Support\Str::of($who)->trim()->afterLast(' '), 0, 1));
                        $when = \Carbon\CarbonImmutable::parse($row['at'])->locale(app()->getLocale())->diffForHumans();
                    @endphp
                    <li class="relative flex gap-3 pb-3.5 {{ ! $loop->last ? 'before:absolute before:bottom-0 before:left-[14px] before:top-8 before:w-px before:bg-border-soft' : '' }}" wire:key="wf-log-{{ $row['id'] }}">
                        <span class="inline-flex size-[30px] shrink-0 items-center justify-center rounded-full bg-admin-primary/10 text-[11.5px] font-bold text-admin-primary">{{ $initial }}</span>
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <p class="flex flex-wrap items-baseline gap-x-2">
                                <span class="text-[13px] font-semibold text-heading">{{ $who }}</span>
                                <span class="text-[12.5px] text-body-text">{{ mb_strtolower($row['transition']) }}</span>
                                <span class="text-[11px] text-body-muted">{{ $when }}</span>
                            </p>
                            <p class="text-[11.5px] text-body-muted">{{ $row['from'] }} → {{ $row['to'] }}</p>
                            @if ($row['note'] !== null)
                                <p class="rounded-r border-l-2 border-border bg-surface-muted px-3 py-2 text-[12.5px] leading-relaxed text-body-text">{{ $row['note'] }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @if ($trailCursor !== null)
                <x-admin::button color="light" size="sm" wire:click="loadMoreTrail" wire:loading.attr="disabled">
                    <x-admin::icon name="ri-arrow-down" class="size-4" />{{ __('admin.wfb.trail_more', ['count' => $trailTotal - count($trail)]) }}
                </x-admin::button>
            @endif
        @endif
    </x-admin::card>

    {{-- Note + hand-off modal, one per transition that requires a reason --}}
    @foreach ($noteActions as $action)
        <div x-cloak x-show="modal === @js($action['key'])" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" wire:key="wf-modal-{{ $action['key'] }}">
            <div class="absolute inset-0 bg-gray-900/50" x-on:click="close()"></div>
            <div class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-md bg-surface shadow-[0_5px_20px_rgba(56,65,74,.28)]">
                <div class="flex items-center gap-2 border-b border-border-soft px-4 py-3.5">
                    <x-admin::icon :name="$action['key'] === 'retract' ? 'ri-eye-off' : 'ri-arrow-go-back'" class="size-4 text-[#c0432c]" />
                    <span class="text-sm font-semibold text-heading">{{ $action['label'] }}</span>
                    <button type="button" class="ml-auto flex size-9 items-center justify-center rounded text-body-muted hover:bg-surface-hover" x-on:click="close()" aria-label="{{ __('admin.close') }}">
                        <x-admin::icon name="ri-close" class="size-5" />
                    </button>
                </div>
                <div class="flex flex-col gap-3 overflow-auto p-4">
                    <p class="flex items-start gap-2 rounded bg-admin-warning/15 px-3 py-2.5 text-[12.5px] leading-relaxed text-[#8a5f18]">
                        <x-admin::icon name="ri-alert" class="mt-0.5 size-4 shrink-0" />
                        <span>{{ $workflow['is_live'] && ! $action['to_published'] ? __('admin.wfb.modal_retract') : __('admin.wfb.modal_return', ['label' => $action['to_label']]) }}</span>
                    </p>
                    <p class="text-[12.5px] leading-relaxed text-body-faint">{{ __('admin.wfb.modal_hint') }}</p>
                    @error('workflow') <p class="text-xs font-semibold text-[#c0432c]">{{ $message }}</p> @enderror
                    <div class="flex flex-col gap-1.5">
                        <label class="text-xs font-semibold text-body-text" for="wf-note-{{ $action['key'] }}">{{ __('admin.wfb.note_label') }} <span class="text-admin-danger">*</span></label>
                        <textarea id="wf-note-{{ $action['key'] }}" x-model="note" x-on:input="bad = false" rows="4"
                                  class="w-full rounded border px-3 py-2.5 text-[13px] text-body-text focus:outline-none focus:ring-[3px] focus:ring-admin-primary/20"
                                  :class="bad ? 'border-admin-danger ring-[3px] ring-admin-danger/15' : 'border-border focus:border-admin-primary'"
                                  placeholder="{{ __('admin.wfb.note_placeholder') }}"></textarea>
                        <p x-cloak x-show="bad" class="flex items-center gap-1.5 text-xs font-semibold text-[#c0432c]">
                            <x-admin::icon name="ri-error-warning" class="size-3.5" />{{ __('admin.wfb.note_missing') }}
                        </p>
                        @error('transitionNote') <p class="text-xs font-semibold text-[#c0432c]">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-xs font-semibold text-body-text" for="wf-who-{{ $action['key'] }}">{{ __('admin.wfb.assign_label') }} <span class="font-normal text-body-muted">{{ __('admin.wfb.assign_optional') }}</span></label>
                        <select id="wf-who-{{ $action['key'] }}" wire:model="assignTo" class="min-h-[38px] w-full rounded border border-border bg-surface px-2.5 text-[13px] text-body-text focus:border-admin-primary focus:outline-none focus:ring-[3px] focus:ring-admin-primary/20">
                            <option value="">{{ __('admin.wfb.assign_none') }}</option>
                            @foreach ($action['members'] as $member)
                                <option value="{{ $member['id'] }}">{{ $member['name'] }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11.5px] leading-relaxed text-body-muted">{{ __('admin.wfb.assign_hint') }}</p>
                        @error('assignTo') <p class="text-xs font-semibold text-[#c0432c]">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-border-soft bg-surface-muted px-4 py-3">
                    <x-admin::button color="light" x-on:click="close()" x-ref="cancel-{{ $action['key'] }}" class="@max-[560px]:w-full pointer-coarse:min-h-11">{{ __('admin.cancel') }}</x-admin::button>
                    <x-admin::button color="danger" variant="outline" x-on:click="submit(@js($action['key']))" wire:loading.attr="disabled" class="@max-[560px]:w-full pointer-coarse:min-h-11">{{ $action['label'] }}</x-admin::button>
                </div>
            </div>
        </div>
    @endforeach
</div>
