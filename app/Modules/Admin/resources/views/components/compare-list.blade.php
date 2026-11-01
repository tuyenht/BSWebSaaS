@props([
    'rows' => [],
    'same' => null,
])
@php
    // What changes between two settings, one row per item (design 03 .kh-cmp): the name, "old →
    // new" and a tag that says the change in words (Thêm / Mất / Tăng / Giảm) with an icon, never
    // by colour alone. `same` closes the list with what stays the same ("4 tính năng giữ nguyên").
    //
    // $rows: list<array{name: string, from: string, to: string, change: 'add'|'lose'|'up'|'down'}>
    $tags = [
        'add' => ['[--c:var(--color-success-text)]', 'ri-add-circle', 'admin.customers_dlg_change_add'],
        'up' => ['[--c:var(--color-success-text)]', 'ri-arrow-up', 'admin.customers_dlg_change_up'],
        'lose' => ['[--c:var(--color-danger-text)]', 'ri-indeterminate-circle', 'admin.customers_dlg_change_lose'],
        'down' => ['[--c:var(--color-warning-text)]', 'ri-arrow-down', 'admin.customers_dlg_change_down'],
    ];
@endphp
<ul {{ $attributes->merge(['class' => 'm-0 flex list-none flex-col rounded-md border border-border p-0']) }}>
    @foreach ($rows as $row)
        @php [$tone, $icon, $word] = $tags[$row['change']] ?? $tags['up']; @endphp
        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border px-3 py-2 text-body last:border-b-0">
            <span class="min-w-0 flex-[1_1_12.5rem] text-text-strong [overflow-wrap:anywhere]">{{ $row['name'] }}</span>
            <span class="shrink-0 whitespace-nowrap text-text">{{ $row['from'] }} <span aria-hidden="true">→</span><span class="sr-only">,</span> {{ $row['to'] }}</span>
            <span class="{{ $tone }} inline-flex shrink-0 items-center gap-1 rounded-full bg-[color-mix(in_srgb,var(--c)_11%,var(--color-surface))] px-2 py-0.5 text-small font-semibold text-(color:--c)">
                <x-bs-admin::icon :name="$icon" class="size-[calc(0.875rem+var(--user-text-boost))] shrink-0" />{{ __($word) }}
            </span>
        </li>
    @endforeach
    @if ($same !== null)
        <li class="px-3 py-2 text-body text-text-muted">{{ $same }}</li>
    @endif
</ul>
