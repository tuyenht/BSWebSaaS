{{-- The pickers a block field opens in «Trình dựng» (inside the host's @can('media.view')): a library
     picture for an image field goes through the shared «Chọn ảnh» dialog (EditsBlockList →
     WithMediaLibraryPicker, the host includes partials/media-picker-modal); a library document for a
     button is chosen here (EditsButtonActions). The host gives $buttonPanel. --}}
{{-- "Tải tệp": a document of the library for a button to hand out. --}}
<x-bs-admin::modal model="showButtonFilePicker" :title="__('entries.btn_file_dialog_title')" :subtitle="__('entries.btn_file_dialog_sub')" :close-label="__('entries.btn_file_dialog_close')">
    @if ($buttonPanel['documents'] === [])
        <div class="btn-act__docs-empty">
            <b>{{ __('entries.btn_file_dialog_empty_title') }}</b>
            <span>{{ __('entries.btn_file_dialog_empty_how') }}</span>
            <a class="btn-act__lib" href="{{ route('admin.media.index') }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
        </div>
    @else
        <div class="btn-act__docs">
            @foreach (array_map(fn (array $doc): array => $doc + ['current' => $doc['key'] === $buttonPanel['fileCurrent']], $buttonPanel['documents']) as $doc)
                <button type="button" class="btn-act__doc" aria-pressed="{{ $doc['current'] ? 'true' : 'false' }}" wire:click="pickButtonFile({{ \Illuminate\Support\Js::from($doc['key']) }})" wire:key="btn-doc-{{ $doc['key'] }}" @if ($doc['current']) autofocus @endif>
                    <span class="btn-act__ic"><x-bs-admin::icon :name="$doc['icon']" class="btn-act__ic-glyph" /></span>
                    <span class="btn-act__kt"><span class="btn-act__name">{{ $doc['name'] }}</span><span class="btn-act__meta">{{ $doc['meta'] }}</span>
                        @if ($doc['current'])
                            <span class="btn-act__doc-cur"><x-bs-admin::icon name="ri-check" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_current') }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    @endif
    <x-slot:footer>
        @if ($buttonPanel['documents'] !== [])
            <a class="btn-act__lib me-auto max-[599.98px]:w-auto! max-[599.98px]:self-start" href="{{ route('admin.media.index') }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
        @endif
        <x-bs-admin::button variant="outline" color="secondary" wire:click="closeButtonFilePicker">{{ __('entries.btn_file_dialog_cancel') }}</x-bs-admin::button>
    </x-slot:footer>
</x-bs-admin::modal>
