{{-- A reusable block's content in «Trình dựng» (board 27 opened from the block library): the band
     naming the block, with «Quay lại» and «Lưu & quay lại»; the website's languages as chips (each
     its own layout); then the content editor's block list and add palette. --}}
@php
    use App\Modules\Admin\Support\Locales;

    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card';
    $error = 'mt-1 text-small text-danger-text';
    $glyph = 'size-[1.15em] shrink-0';
    $rowButton = 'hit-area inline-flex size-control-row shrink-0 items-center justify-center rounded border border-border bg-surface text-text-muted transition-colors hover:bg-surface-hover hover:text-text';
    // «Xóa khối?» asks first and names the language whose layout loses it, as in the content editor
    $askDelete = fn (string $text): string => '$bsConfirm({ icon: \'trash-danger\', title: '.\Illuminate\Support\Js::from(__('entries.ed.del_title'))
        .', text: '.\Illuminate\Support\Js::from($text.' '.__('entries.ed.del_final'))
        .', confirm: '.\Illuminate\Support\Js::from(__('entries.ed.block_remove'))
        .', cancel: '.\Illuminate\Support\Js::from(__('admin.cancel')).' })';
    $emptyText = __('blocks.editor_empty', ['lang' => $localeLabel]);
@endphp
<div class="flex flex-col gap-4">
    <h1 class="sr-only">{{ $title }}</h1>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-border bg-primary-soft px-4 py-2.5">
        <span class="inline-flex min-w-0 flex-[1_1_16rem] items-start gap-2 text-small font-semibold text-primary-text">
            <x-bs-admin::icon name="ri-stack" class="mt-0.5 size-4 shrink-0" />
            <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('blocks.editor_band', ['name' => $blockName]) }}{{ __('blocks.editor_band_note') }}</span>
        </span>
        <span class="ms-auto flex flex-wrap gap-2">
            <x-bs-admin::button variant="outline" color="primary" :href="route('admin.blocks.index')" wire:navigate title="{{ __('blocks.editor_back') }}">
                <x-bs-admin::icon name="ri-arrow-left" class="size-4" /> {{ __('blocks.editor_back') }}
            </x-bs-admin::button>
            <x-bs-admin::button color="primary" wire:click="saveAndReturn" wire:loading.attr="disabled" wire:target="saveAndReturn,save">
                <x-bs-admin::icon name="ri-save-3" class="size-4" /> {{ __('blocks.editor_save_back') }}
            </x-bs-admin::button>
        </span>
    </div>

    @if ($locales !== [])
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('blocks.editor_langs') }}">
            <span class="text-small font-semibold text-text">{{ __('blocks.editor_langs') }}</span>
            @foreach ($locales as $lc)
                @php $on = $lc === $current; @endphp
                <a href="{{ route('admin.blocks.edit', ['ulid' => $ulid, 'locale' => $lc]) }}" wire:navigate wire:key="lc-{{ $lc }}"
                   @if ($on) aria-current="page" @endif
                   @class([
                       'hit-area inline-flex min-h-control-row items-center gap-1.5 rounded-full border px-3 text-small font-semibold transition-colors',
                       'border-primary-text bg-primary-soft text-primary-text' => $on,
                       'border-border bg-surface text-text-muted hover:bg-surface-hover hover:text-text' => ! $on,
                   ])>
                    <x-bs-admin::icon name="ri-translate-2" class="size-4 shrink-0" />{{ Locales::label($lc) }}
                </a>
            @endforeach
        </div>
    @endif

    <section class="{{ $card }} overflow-hidden" aria-labelledby="block-body-h" x-data="{ tab: 'builder', openBlock: $wire.entangle('openBlock') }">
        <div class="flex flex-col gap-3 px-5 pt-4 pb-5">
            <div>
                <h2 id="block-body-h" class="m-0 text-body font-semibold text-text-strong">{{ __('blocks.editor_head', ['n' => count($blocks)]) }}</h2>
                <p class="m-0 mt-0.5 text-small text-text-muted">{{ __('blocks.editor_sub', ['lang' => $localeLabel]) }}</p>
            </div>
            @include('admin::livewire.entries.partials.block-rows')
            @include('admin::livewire.entries.partials.block-add')
        </div>
    </section>

    @can('media.view')
        @include('admin::livewire.entries.partials.block-pickers')
    @endcan

    <x-bs-admin::unsaved-dialog :count="$changes" :label="$title" save="save" discard="resetChanges"
        :body="__('blocks.leave_body')" :save-label="__('blocks.leave_save')" :discard-label="__('blocks.leave_discard')" />
    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" />
</div>
