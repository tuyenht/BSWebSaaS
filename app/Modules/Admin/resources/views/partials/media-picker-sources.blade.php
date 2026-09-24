{{-- Upload tab — source-select screen (handoff screen 1). Phase 2 renders the full grid; the
     tiles activate per phase (device+camera → Phase 3, clouds/Unsplash → Phase 5 behind their
     OAuth keys). Until wired, a tile is visibly inert (muted + tooltip) — never a silent no-op. --}}
@php($sources = [
    ['key' => 'device',   'icon' => 'ri-computer',      'label' => __('media.picker_source_device'), 'color' => '#405189'],
    ['key' => 'camera',   'icon' => 'ri-camera',        'label' => __('media.picker_source_camera'), 'color' => '#0ab39c'],
    ['key' => 'dropbox',  'icon' => 'ri-dropbox',       'label' => 'Dropbox',       'color' => '#0061FF'],
    ['key' => 'gdrive',   'icon' => 'ri-drive',         'label' => 'Google Drive',  'color' => '#1a73e8'],
    ['key' => 'gphotos',  'icon' => 'ri-google',        'label' => 'Google Photos', 'color' => '#EA4335'],
    ['key' => 'onedrive', 'icon' => 'ri-microsoft',     'label' => 'OneDrive',      'color' => '#0364B7'],
    ['key' => 'unsplash', 'icon' => 'ri-camera-lens',   'label' => 'Unsplash',      'color' => '#111827'],
    ['key' => 'box',      'icon' => 'ri-inbox-archive', 'label' => 'Box',           'color' => '#0061D5'],
])
{{-- Compact geometry (handoff Fidelity "KHÔNG CUỘN"): hero 46px, tile icon 44px, tile pad
     13/8 — the hero + 4×2 grid + URL row all fit the standard modal height with no scrollbar.
     A short viewport (<620px) still scrolls as a fallback via the parent's overflow-auto. --}}
@php($canUpload = auth()->user()?->can('media.upload'))
<div class="flex flex-1 p-[18px]">
    {{-- The whole dashed box doubles as a quick-upload dropzone (drag-drop OR "browse files") for
         media.upload users: it stages the files client-side and jumps to the device pane's uploader. --}}
    <div class="flex flex-1 flex-col justify-center rounded-2xl border-2 border-dashed border-[#dfe3ea] bg-surface-muted px-[22px] py-7 transition-colors"
         @if ($canUpload) x-data="pickerQuickUpload" x-on:dragover.prevent x-on:drop.prevent="onDrop($event)" @endif>
        <div class="mb-4 flex flex-col items-center gap-[5px] text-center">
            <div class="flex size-[46px] items-center justify-center rounded-full bg-admin-primary/8">
                <x-admin::icon name="ri-upload-cloud-2" class="size-[22px] text-admin-primary" />
            </div>
            <div class="text-[15px] font-semibold text-heading">{{ __('media.picker_drop_title') }}</div>
            <div class="text-[12.5px] text-body-muted">
                {{ __('media.picker_drop_or') }}
                @if ($canUpload)
                    <button type="button" x-on:click="pick()" class="cursor-pointer font-semibold text-admin-primary underline">{{ __('media.picker_drop_browse') }}</button>
                    <input type="file" x-ref="quickInput" multiple accept="image/*,application/pdf" class="hidden" x-on:change="onChange($event)">
                @else
                    <span class="font-semibold text-admin-primary underline opacity-60">{{ __('media.picker_drop_browse') }}</span>
                @endif
                · {{ __('media.picker_drop_import') }}
            </div>
        </div>
        <div class="mx-auto grid w-full max-w-[640px] grid-cols-2 gap-3 min-[480px]:grid-cols-3 min-[720px]:grid-cols-4">
            @foreach ($sources as $source)
                @php($active = $source['key'] === 'device' && $canUpload)
                <button type="button" wire:key="mp-src-{{ $source['key'] }}" @disabled(! $active)
                        @if ($active) wire:click="openPickerSource('device')" @else title="{{ __('media.picker_source_soon') }}" @endif
                        @class([
                            'relative flex min-w-0 flex-col items-center gap-2 rounded-[13px] border bg-surface px-2 py-[13px] transition-all',
                            'cursor-pointer border-border hover:-translate-y-0.5 hover:border-admin-primary hover:shadow-[0_6px_16px_-8px_rgba(64,81,137,0.4)]' => $active,
                            'cursor-not-allowed border-border opacity-60' => ! $active,
                        ])>
                    <span class="flex size-11 items-center justify-center rounded-xl"
                          style="background: {{ $source['color'] }}1a; color: {{ $source['color'] }};">
                        <x-admin::icon :name="$source['icon']" class="size-[22px]" />
                    </span>
                    <span class="max-w-full truncate text-center text-xs font-medium leading-snug text-body-text">{{ $source['label'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- "— hoặc dán liên kết ảnh —" (handoff §1b): format-check here, the REAL gate is the
             server-side SSRF-guarded fetch in previewPickerUrl → ExternalImageFetcher. Only for
             media.upload — a URL fetch stages a real R2 object, so it's an upload-class action. --}}
        @can('media.upload')
            <div class="mx-auto mt-3.5 flex w-full max-w-[640px] items-center gap-3">
                <span class="h-px flex-1 bg-border-strong"></span>
                <span class="text-[11.5px] text-body-muted">{{ __('media.picker_url_divider') }}</span>
                <span class="h-px flex-1 bg-border-strong"></span>
            </div>
            <div class="mx-auto mt-2.5 flex w-full max-w-[640px] gap-2">
                <div class="relative min-w-0 flex-1">
                    <x-admin::icon name="ri-link" class="absolute left-3 top-1/2 size-[15px] -translate-y-1/2 text-body-muted" />
                    <input type="url" wire:model="pickerUrlInput" wire:keydown.enter="previewPickerUrl"
                           placeholder="{{ __('media.picker_url_placeholder') }}"
                           class="block w-full rounded-lg border border-border-strong bg-surface py-[9px] pl-[35px] pr-3 text-[13px] text-body-text placeholder:text-placeholder focus:border-admin-primary">
                </div>
                <button type="button" wire:click="previewPickerUrl"
                        class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-admin-primary/20 bg-admin-primary/4 px-[15px] py-[9px] text-[12.5px] font-semibold text-admin-primary">
                    <x-admin::icon name="ri-eye" class="size-3.5" />{{ __('media.picker_url_preview') }}
                </button>
            </div>
            @error('pickerUrlInput')
                <div class="mx-auto mt-2 flex w-full max-w-[640px] items-center gap-1.5 text-xs text-admin-danger-text">
                    <x-admin::icon name="ri-error-warning" class="size-3.5 shrink-0" />{{ $message }}
                </div>
            @enderror
        @endcan
    </div>
</div>
