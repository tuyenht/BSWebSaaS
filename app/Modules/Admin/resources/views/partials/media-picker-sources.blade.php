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
<div class="flex flex-1 p-[18px]">
    <div class="flex flex-1 flex-col justify-center rounded-2xl border-2 border-dashed border-[#dfe3ea] bg-surface-muted px-[22px] py-7">
        <div class="mb-6 flex flex-col items-center gap-[7px] text-center">
            <div class="flex size-14 items-center justify-center rounded-full bg-admin-primary/8">
                <x-admin::icon name="ri-upload-cloud-2" class="size-[27px] text-admin-primary" />
            </div>
            <div class="text-base font-semibold text-heading">{{ __('media.picker_drop_title') }}</div>
            <div class="text-[13px] text-body-muted">
                {{ __('media.picker_drop_or') }}
                <span class="cursor-not-allowed font-semibold text-admin-primary underline" title="{{ __('media.picker_source_soon') }}">{{ __('media.picker_drop_browse') }}</span>
                · {{ __('media.picker_drop_import') }}
            </div>
        </div>
        <div class="mx-auto grid w-full max-w-[640px] grid-cols-4 gap-3">
            @foreach ($sources as $source)
                <button type="button" wire:key="mp-src-{{ $source['key'] }}" disabled
                        title="{{ __('media.picker_source_soon') }}"
                        class="relative flex cursor-not-allowed flex-col items-center gap-[11px] rounded-[13px] border border-border bg-surface px-2.5 py-[18px] opacity-60">
                    <span class="flex size-[52px] items-center justify-center rounded-[14px]"
                          style="background: {{ $source['color'] }}1a; color: {{ $source['color'] }};">
                        <x-admin::icon :name="$source['icon']" class="size-[26px]" />
                    </span>
                    <span class="text-center text-[12.5px] font-medium leading-snug text-body-text">{{ $source['label'] }}</span>
                </button>
            @endforeach
        </div>
    </div>
</div>
