{{-- «Nâng cao — các dòng xác thực tên miền» (board c-email-settings, under «Kiểm tra & lưu kết nối»):
     one row per record with its dot, name, what it does and a state chip in words (never colour
     alone). Polls only while a check is under way (3s) or re-checking every five minutes (60s). --}}
@php
    $dot = [
        'ok' => 'bg-success-base',
        'pending' => 'bg-warning-base',
        'failed' => 'bg-danger-base',
        'unchecked' => 'border-[1.5px] border-dashed border-text-faint',
        'idle' => 'border-[1.5px] border-dashed border-text-faint',
    ];
    $chip = [
        'ok' => 'bg-success-base/12 text-success-text',
        'pending' => 'bg-warning-base/16 text-warning-text',
        'failed' => 'bg-danger-base/12 text-danger-text',
        'unchecked' => 'bg-text-faint/16 text-text-muted',
        'idle' => 'bg-text-faint/16 text-text-muted',
    ];
@endphp
<div class="mt-4 overflow-hidden rounded-md border border-border-strong" data-dns-box data-dns-phase="{{ $phase }}"
     @if ($phase === 'running') wire:poll.3s @elseif ($phase === 'auto') wire:poll.60s @endif>
    <div class="flex flex-wrap items-center justify-between gap-x-2.5 gap-y-1 border-b border-border bg-surface-muted px-3.5 py-2.5">
        <h3 id="em-dns-title" class="text-caption font-bold tracking-[.04em] text-text-muted">{{ __('admin.em_dns_title') }}</h3>
        <span class="text-caption text-text-muted" aria-hidden="true">{{ $hint }}</span>
        <x-bs-admin::sr-live>{{ $hint }}</x-bs-admin::sr-live>
    </div>
    @if ($wait !== '')
        <div class="border-b border-border p-2.5">
            <x-bs-admin::alert color="warning" icon="ri-time" role="status" data-dns-wait>{{ $wait }}</x-bs-admin::alert>
        </div>
    @endif
    <ul role="list" aria-labelledby="em-dns-title">
        @foreach ($rows as $r)
            <li class="flex flex-wrap items-center gap-x-[11px] gap-y-1.5 border-t border-border px-3.5 py-[11px]" wire:key="dns-{{ $r['key'] }}" data-dns-row="{{ $r['key'] }}" data-dns-tone="{{ $r['tone'] }}">
                <span aria-hidden="true" class="size-[9px] shrink-0 rounded-full {{ $dot[$r['tone']] }}"></span>
                <span class="w-16 shrink-0 text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $r['name'] }}</span>
                <span class="flex min-w-0 flex-[1_1_12rem] flex-col gap-1">
                    <span class="text-[calc(0.78125rem+var(--user-text-boost))] leading-[1.45] text-text-muted">{{ $r['desc'] }}</span>
                    @if ($r['note'] !== '')
                        <span class="text-caption leading-normal text-text [overflow-wrap:anywhere]" data-dns-note>{{ $r['note'] }}</span>
                    @endif
                </span>
                <span class="ms-auto shrink-0 rounded-[20px] px-[9px] py-0.5 text-caption font-semibold whitespace-nowrap {{ $chip[$r['tone']] }}">{{ $r['state'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
