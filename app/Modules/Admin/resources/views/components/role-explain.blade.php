@props([
    'areas' => [],
    'idPrefix' => 'rx',
])
@php
    // What a role lets its holder do, area by area (RoleAbilities::explain): «Được …» with a tick, «Không được …»
    // greyed with a cross, and a note under an area whose module the website's plan has switched off. The body of
    // «Nhóm này làm được gì?» on Thành viên (a centred dialog) and on Nhóm quyền (a side panel).
    //
    // $areas: list<array{key: string, name: string, off: string, items: list<array{ok: bool, text: string}>}>
    // $idPrefix: keeps the headings' ids apart when two screens' panels could share a page.
@endphp
@foreach ($areas as $area)
    <section class="flex min-w-0 flex-col gap-1.5" aria-labelledby="{{ $idPrefix }}-{{ $area['key'] }}">
        <h3 id="{{ $idPrefix }}-{{ $area['key'] }}" class="m-0 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $area['name'] }}</h3>
        @if ($area['off'] !== '')
            <p class="m-0 flex items-start gap-1.5 text-small text-text-muted"><x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $area['off'] }}</span></p>
        @endif
        <ul class="m-0 flex list-none flex-col gap-1 p-0">
            @foreach ($area['items'] as $item)
                <li @class(['flex items-start gap-2 text-body', 'text-text' => $item['ok'], 'text-text-muted' => ! $item['ok']])>
                    <x-bs-admin::icon :name="$item['ok'] ? 'ri-checkbox-circle' : 'ri-close-circle'" @class(['mt-0.5 size-4.5 shrink-0', 'text-success-text' => $item['ok'], 'text-text-muted' => ! $item['ok']]) />
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ $item['text'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endforeach
