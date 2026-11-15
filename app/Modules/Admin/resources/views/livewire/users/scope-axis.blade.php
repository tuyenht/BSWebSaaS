{{-- One axis of "Phạm vi làm việc" (design 22), the same in the invite dialog and a member's panel:
     $a = the axis as the component worked it out, $kind = "inv" | "pn", $prefix = "invite" | "panel"
     (the component's state arrays, keyed by axis). --}}
@php
    $ax = $a['axis'];
    $slug = $ax === \App\Modules\Cms\Support\CategoryScopes::PRODUCTS ? 'products' : 'posts';
    $radio = 'members-'.$kind.'-sc-'.$slug;
@endphp
<fieldset class="m-0 flex min-w-0 flex-col gap-1 border-0 border-t border-border p-0 pt-2" wire:key="members-{{ $kind }}-sc-{{ $slug }}">
    <legend class="contents"><span class="flex flex-wrap items-center gap-x-2 gap-y-1.5 text-body font-semibold text-text-strong"><x-bs-admin::icon :name="$a['words']['icon']" class="size-4 shrink-0" />{{ $a['words']['title'] }}</span></legend>
    @if ($a['ro'])
        <p class="m-0 flex items-start gap-1.5 text-small text-text-muted"><x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span><b class="text-text-strong">{{ $a['cur'] }}</b> · {{ $a['roText'] }}</span></p>
    @endif
    @if ($a['lim'])
        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 text-small text-text">
            <span class="min-w-0 [overflow-wrap:anywhere]">{{ $a['limText'] }}</span>
            @if ($a['liftLocked'])
                <x-bs-admin::button size="sm" variant="outline" color="secondary" aria-disabled="true" class="aria-disabled:opacity-60"><x-bs-admin::icon name="ri-lock-unlock" class="size-4" /> {{ __('admin.members.sc_lift') }}</x-bs-admin::button>
            @else
                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="$set('{{ $prefix }}Scope.{{ $ax }}', 'all')"><x-bs-admin::icon name="ri-lock-unlock" class="size-4" /> {{ __('admin.members.sc_lift') }}</x-bs-admin::button>
            @endif
            <span class="text-small text-text-muted">{{ $a['liftNote'] }}</span>
        </div>
    @endif
    @if ($a['radios'])
        <label @class(['flex min-h-hit items-center gap-2.5', 'cursor-pointer text-text-strong' => ! $a['allDis'], 'cursor-not-allowed text-text-muted' => $a['allDis']])>
            <input type="radio" name="{{ $radio }}" value="all" wire:model.live="{{ $prefix }}Scope.{{ $ax }}" @checked($a['mode'] === 'all') @disabled($a['allDis']) class="size-4.5 shrink-0 accent-primary-text">
            <span>{{ $a['words']['all'] }}</span>
        </label>
        @if ($a['allWhy'])
            <p class="m-0 -mt-1.5 mb-1 ms-7 text-small text-text-muted">{{ __('admin.members.inv_sc_own') }}</p>
        @endif
        <label @class(['flex min-h-hit items-center gap-2.5', 'cursor-pointer text-text-strong' => ! $a['onlyDis'], 'cursor-not-allowed text-text-muted' => $a['onlyDis']])>
            <input type="radio" name="{{ $radio }}" value="only" wire:model.live="{{ $prefix }}Scope.{{ $ax }}" @checked($a['mode'] === 'only') @disabled($a['onlyDis']) class="size-4.5 shrink-0 accent-primary-text">
            <span>{{ $a['words']['only'] }}</span>
        </label>
        @if ($a['tree'])
            <div class="ms-7 flex min-w-0 flex-col gap-1.5">
                <x-bs-admin::search :label="$a['words']['q']" :placeholder="$a['words']['ph']" wire:model.live.debounce.250ms="{{ $prefix }}CatQ.{{ $ax }}" maxlength="100" />
                <p class="m-0 text-small text-text-muted">{{ __('admin.members.inv_sc_hint') }}</p>
                <div class="flex max-h-[340px] min-w-0 flex-col overflow-x-hidden overflow-y-auto rounded border border-border-strong p-1" role="group" aria-label="{{ $a['words']['tree'] }}">
                    @foreach ($a['rows'] as $node)
                        <label wire:key="members-{{ $kind }}-sc-{{ $node['ulid'] }}" @style(['--i: '.($node['depth'] * 24).'px'])
                            @class(['relative flex min-h-hit items-center gap-2.5 rounded py-0 pe-2 ps-[calc(8px+var(--i))] hover:bg-surface-hover',
                                'before:absolute before:start-[calc(var(--i)-10px)] before:top-0 before:h-1/2 before:w-2.5 before:border-b before:border-s before:border-border-strong' => $node['depth'] > 0,
                                'cursor-pointer text-text-strong' => ! $node['dis'], 'cursor-not-allowed text-text-muted' => $node['dis']])>
                            @if ($node['inc'])
                                <input type="checkbox" checked disabled class="size-4.5 shrink-0 accent-primary-text">
                            @else
                                <input type="checkbox" value="{{ $node['ulid'] }}" wire:model.live="{{ $prefix }}Categories.{{ $ax }}" @disabled($node['dis']) class="size-4.5 shrink-0 accent-primary-text">
                            @endif
                            <span class="min-w-0 flex-auto [overflow-wrap:anywhere]">@if ($node['path'] !== '')<span class="text-text-muted">{{ $node['path'] }}</span>@endif{{ $node['name'] }}</span>
                            @if ($node['note'] !== '')
                                <span class="shrink-0 text-caption text-text-muted">{{ $node['note'] }}</span>
                            @endif
                        </label>
                    @endforeach
                    @if ($a['rows'] === [] && trim($a['q']) !== '')
                        <p class="m-0 flex items-start gap-1.5 p-2 text-small text-text-muted">{{ __('admin.members.inv_sc_nohit') }}</p>
                    @endif
                </div>
            </div>
        @endif
        @if ($a['error'] !== '')
            <x-bs-admin::field-error>{{ $a['error'] }}</x-bs-admin::field-error>
        @endif
    @endif
</fieldset>
