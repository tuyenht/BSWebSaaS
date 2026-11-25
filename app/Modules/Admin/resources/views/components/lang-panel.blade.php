@props(['label', 'none' => null])
{{-- The panel of every language version that a language row's «+N» opens: a dialog named $label. Used by
     the shared language bar (board 07, <x-bs-admin::lang-bar>) and the categories drawer's language row
     (board 10), each with its own rows, look and Alpine state. The slot holds the panel's head, its
     search box and its rows; $none is the Alpine expression that is true when the search finds no
     language, and with it the panel says so («Không tìm thấy…», read out as it appears). The `foot`
     slot comes last («Quản lý ngôn ngữ»). --}}
<div role="dialog" aria-label="{{ $label }}" {{ $attributes }}>
    {{ $slot }}
    @if ($none !== null)
        <p class="lb-none" role="status" x-show="{{ $none }}" x-cloak>{{ __('admin.lb.no_result') }}</p>
    @endif
    {{ $foot ?? '' }}
</div>
