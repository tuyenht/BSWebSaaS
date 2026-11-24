@props([
    // the group's key: the parent's Alpine `open` holds the key of the one open group
    'key',
    'title',
    // «4 mục» at the end of the header, when given
    'count' => null,
    // «2 lỗi» before the count, when above 0
    'bad' => 0,
    // the group open on first paint (the others wait for Alpine, so they never flash open)
    'first' => false,
    'id' => null,
])
@php
    // One foldable group of a settings section (design board «Khung quản trị»: «Bố cục · 4 mục»): a header
    // button with + / − , the title, an error chip and the number of fields; one group is open at a time,
    // through the parent's x-data `open`. The slot is the group's body; its classes (columns, gaps) come
    // through the component's attributes.
    $bodyId = $id ?? 'set-g-'.$key;
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $k = \Illuminate\Support\Js::from($key);
@endphp
<div class="overflow-hidden rounded-[9px] border bg-surface"
     x-bind:class="open === {{ $k }} ? 'border-primary-text/25 shadow-card' : 'border-border'">
    <h2>
        <button type="button" class="flex min-h-control-row w-full items-center gap-2.5 px-4 py-3 text-start hover:bg-surface-hover"
                x-bind:class="open === {{ $k }} && 'bg-primary-soft/60'"
                x-on:click="open = open === {{ $k }} ? null : {{ $k }}"
                x-bind:aria-expanded="open === {{ $k }} ? 'true' : 'false'" aria-controls="{{ $bodyId }}">
            <span class="inline-flex shrink-0 text-text-muted" x-show="open !== {{ $k }}"><x-bs-admin::icon name="ri-add" :class="$glyph" /></span>
            <span class="inline-flex shrink-0 text-primary-text" x-show="open === {{ $k }}" x-cloak><x-bs-admin::icon name="ri-subtract" :class="$glyph" /></span>
            <span class="min-w-0 flex-1 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $title }}</span>
            @if ($bad > 0)
                <span class="shrink-0 rounded-full bg-danger-soft px-2 py-0.5 text-caption font-bold whitespace-nowrap text-danger-text">{{ __('admin.gs.errs', ['count' => $bad]) }}</span>
            @endif
            @if ($count !== null)
                <span class="shrink-0 text-caption whitespace-nowrap text-text-muted">{{ __('admin.gs.items', ['count' => $count]) }}</span>
            @endif
        </button>
    </h2>
    <div id="{{ $bodyId }}" x-show="open === {{ $k }}" @unless ($first) x-cloak @endunless
         {{ $attributes->merge(['class' => 'border-t border-border px-4 pt-4 pb-[18px]']) }}>
        {{ $slot }}
    </div>
</div>
