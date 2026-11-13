{{--
    The recovery codes of My account, shown once right after they are made: the list, then a
    download (ma-khoi-phuc.txt) and a copy button. Both run in the browser only; nothing is sent.
--}}
@php
    $file = __('admin.account.codes_file_head')."\n".__('admin.account.codes_file_once')."\n\n".implode("\n", $codes)."\n";
@endphp
<div class="flex flex-col gap-3" x-data="{ copied: false, text: @js($file) }">
    <ul class="ac-codes ac-mono" aria-label="{{ $label }}">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>
    <div class="flex flex-wrap items-center gap-2">
        <x-bs-admin::button variant="outline" color="secondary"
                            @click="const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' })); a.download = 'ma-khoi-phuc.txt'; a.click(); URL.revokeObjectURL(a.href)">
            <x-bs-admin::icon name="ri-download-2" class="size-[1.15em] shrink-0" />{{ __('admin.account.download') }}
        </x-bs-admin::button>
        <x-bs-admin::button variant="outline" color="secondary" @click="navigator.clipboard?.writeText(text).then(() => copied = true)">
            <x-bs-admin::icon name="ri-file-copy" class="size-[1.15em] shrink-0" />
            <span x-text="copied ? @js(__('admin.account.copied')) : @js(__('admin.account.copy'))">{{ __('admin.account.copy') }}</span>
        </x-bs-admin::button>
    </div>
</div>
