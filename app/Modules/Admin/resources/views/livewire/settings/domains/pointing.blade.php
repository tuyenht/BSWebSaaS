{{--
    "Trỏ tên miền về hệ thống": the rows to add at the registrar (a table from 600, one card per row
    below), the shared target with [Sao chép], and — in the list, not in the add dialog — what to do
    when the registrar cannot point "@" at a name, the advanced note and "Nhờ hỗ trợ trỏ giúp".
    In: $group, $records, $apex (move|ip|null), $dialog (bool), $locked (bool), $manage (bool).
--}}
@php
    $hasApex = collect($records)->contains(fn (array $r): bool => $r['label'] === '@');
    $kind = fn (array $r): string => __($r['kind'] === 'ip' ? 'admin.domains_point_kind_ip' : 'admin.domains_point_kind_name');
    $use = fn (array $r): string => $r['runs'] ? __('admin.domains_point_use_run') : __('admin.domains_point_use_redirect', ['host' => \App\Modules\Tenancy\Domains\DomainName::display($group->runs)]);
    $name = \App\Modules\Tenancy\Domains\DomainName::display($group->name);
@endphp
<div class="flex min-w-0 basis-full flex-col gap-2.5 rounded-md border border-border bg-surface p-3" role="group"
     aria-label="{{ __('admin.domains_point_table_for', ['name' => $name]) }}">
    <span class="font-semibold text-text-strong">{{ __('admin.domains_point_title') }}</span>
    <span class="text-small text-text-muted">{{ __('admin.domains_point_intro', ['n' => count($records)]) }}</span>
    <x-bs-admin::copy-field :value="\App\Modules\Tenancy\Domains\PointingRecords::target()" icon="ri-map-pin-2" copied="copied" :args="['target', $group->name]" />

    <div class="grid grid-cols-[minmax(0,.7fr)_minmax(0,.9fr)_minmax(0,1.6fr)_minmax(0,1fr)] rounded border border-border text-small @max-[37.4999rem]:hidden"
         role="table" aria-label="{{ __('admin.domains_point_table_aria') }}">
        <div role="row" class="contents">
            @foreach (['name', 'kind', 'target', 'use'] as $col)
                <div role="columnheader" class="min-w-0 bg-surface-muted px-2.5 py-1.5 font-semibold text-text-muted">{{ __('admin.domains_point_col_'.$col) }}</div>
            @endforeach
        </div>
        @foreach ($records as $r)
            <div role="row" class="contents">
                <div role="cell" class="min-w-0 border-t border-border px-2.5 py-1.5 font-mono font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $r['label'] }}</div>
                <div role="cell" class="min-w-0 border-t border-border px-2.5 py-1.5 [overflow-wrap:anywhere]">{{ $kind($r) }}</div>
                <div role="cell" class="min-w-0 border-t border-border px-2.5 py-1.5 font-mono font-medium text-text-strong [overflow-wrap:anywhere]">{{ $r['target'] }}</div>
                <div role="cell" class="min-w-0 border-t border-border px-2.5 py-1.5 [overflow-wrap:anywhere]">{{ $use($r) }}</div>
            </div>
        @endforeach
    </div>
    <ul class="m-0 hidden list-none flex-col gap-1 p-0 @max-[37.4999rem]:flex" aria-label="{{ __('admin.domains_point_table_aria') }}">
        @foreach ($records as $r)
            <li class="flex flex-col gap-1 rounded border border-border px-2.5 py-2 text-small">
                <span><b class="font-mono text-text-strong">{{ $r['label'] }}</b> · {{ $use($r) }}</span>
                <span class="text-text-muted">{{ $kind($r) }} → <span class="font-mono font-medium text-text-strong [overflow-wrap:anywhere]">{{ $r['target'] }}</span></span>
            </li>
        @endforeach
    </ul>

    @if ($dialog)
        <span class="text-caption text-text-muted">{{ $hasApex ? __('admin.domains_dlg_point_apex_note') : __('admin.domains_dlg_point_sub_note') }}</span>
    @else
        @if ($apex === 'ip')
            <x-bs-admin::alert color="warning" icon="ri-error-warning" role="status" class="text-small">{{ __('admin.domains_point_ip_warning') }}</x-bs-admin::alert>
        @endif
        @if ($hasApex)
            <details class="border-t border-border pt-2 text-small" @if ($apex !== null) open @endif>
                <summary class="hit-area flex min-h-control-row cursor-pointer list-none items-center gap-1.5 font-semibold text-primary-text any-pointer-coarse:min-h-hit [&::-webkit-details-marker]:hidden">
                    <x-bs-admin::icon name="ri-arrow-right-s" class="size-[calc(1rem+var(--user-text-boost))] shrink-0 transition-transform in-open:rotate-90 motion-reduce:transition-none" />
                    {{ __('admin.domains_point_apex_q') }}
                </summary>
                <div class="mt-1.5 flex flex-col gap-2">
                    <span class="text-text-muted">{{ __('admin.domains_point_apex_intro') }}</span>
                    @foreach (['move' => 'admin.domains_point_opt_move', 'ip' => 'admin.domains_point_opt_ip'] as $option => $key)
                        <button type="button" aria-pressed="{{ $apex === $option ? 'true' : 'false' }}" wire:click="pickApex({{ \Illuminate\Support\Js::from($group->name) }}, '{{ $option }}')"
                                class="flex w-full min-w-0 cursor-pointer flex-col items-start gap-1 rounded-md border border-border-input bg-surface px-3 py-2.5 text-left aria-pressed:border-2 aria-pressed:border-primary-text aria-pressed:px-[0.6875rem] aria-pressed:py-[0.5625rem]">
                            <span class="font-semibold text-text-strong">{{ __($key.'_title') }}</span>
                            <span @class(['text-text-muted' => $option === 'move', 'text-warning-text' => $option === 'ip'])>{{ __($key.'_body') }}</span>
                        </button>
                    @endforeach
                </div>
            </details>
        @endif
        <details class="border-t border-border pt-2 text-small">
            <summary class="hit-area flex min-h-control-row cursor-pointer list-none items-center gap-1.5 font-semibold text-primary-text any-pointer-coarse:min-h-hit [&::-webkit-details-marker]:hidden">
                <x-bs-admin::icon name="ri-arrow-right-s" class="size-[calc(1rem+var(--user-text-boost))] shrink-0 transition-transform in-open:rotate-90 motion-reduce:transition-none" />
                {{ __('admin.domains_advanced') }}
            </summary>
            <p class="m-0 mt-1.5">{{ __('admin.domains_point_advanced_body') }}</p>
        </details>
        @if ($manage)
            <div class="flex flex-wrap items-center gap-2">
                <x-bs-admin::button variant="ghost" color="primary" size="sm" wire:click="requestHelp({{ \Illuminate\Support\Js::from($group->name) }})" :aria-disabled="$locked ? 'true' : null">
                    <x-bs-admin::icon name="ri-customer-service-2" class="size-[calc(1rem+var(--user-text-boost))]" />
                    <span>{{ __('admin.domains_point_help_btn') }}</span>
                </x-bs-admin::button>
            </div>
        @endif
    @endif
</div>
