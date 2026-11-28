@props([
    // Alpine expressions of the screen's own state: whether something is unsaved, whether a save is running,
    // and what «Lưu thay đổi» and «Bỏ thay đổi» call
    'dirty' => 'false',
    'busy' => 'false',
    'save' => 'save()',
    'reset' => 'reset()',
    // why saving is not possible now (an Alpine expression giving a string, or '' when it is)
    'why' => "''",
    // what «Lưu thay đổi» reads while the save runs (plain text; null keeps the label)
    'saving' => null,
])
@php
    // The unsaved cluster of an editing screen's head (design shell-header.js saveHtml, board «Khung quản trị»):
    // «Chưa lưu» while something is unsaved, the screen's own secondary button (the slot, e.g. «Xem trên
    // website»), «Bỏ thay đổi» and «Lưu thay đổi», both off until there is a change. Below 600 only the
    // secondary button stays in the head: saving moves to the screen's bar at the bottom.
    $glyph = 'size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0';
@endphp
<div {{ $attributes->merge(['class' => 'flex min-w-0 flex-[0_1_auto] flex-wrap items-center gap-2.5 max-[599.98px]:[&>:not([data-head-save-keep])]:hidden']) }} data-head-save>
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-caption text-warning-text" x-show="{{ $dirty }}" x-cloak role="status">
        <x-bs-admin::icon name="ri-error-warning" :class="$glyph" />{{ __('admin.head.unsaved') }}
    </span>
    @if ($slot->isNotEmpty())
        <span data-head-save-keep class="contents">{{ $slot }}</span>
    @endif
    {{-- PHP-valued attributes: a component escapes its attributes once more, so {{ }} inside one would turn && into &amp;amp; --}}
    <x-bs-admin::button variant="outline" color="secondary" data-guide="reset" disabled :x-on:click="$reset" :x-bind:disabled="'!('.$dirty.') || ('.$busy.')'">
        {{ __('admin.head.discard') }}
    </x-bs-admin::button>
    <x-bs-admin::button data-guide="save" disabled :x-on:click="$save" :x-bind:disabled="'!('.$dirty.') || ('.$busy.') || ('.$why.') !== \'\''" :x-bind:title="'('.$why.') || null'">
        <x-bs-admin::icon name="ri-save-3" class="size-4" /><span @if ($saving !== null) x-text="({{ $busy }}) ? {{ \Illuminate\Support\Js::from($saving) }} : {{ \Illuminate\Support\Js::from(__('admin.head.save')) }}" @endif>{{ __('admin.head.save') }}</span>
    </x-bs-admin::button>
</div>
