@php
    use App\Modules\Admin\Livewire\WebsiteAppearanceSettings;
    use Illuminate\Support\Js;

    // "Giao diện website" (design: settings section `appearance`), in the frame "Thông tin chung" and
    // "Google & mạng xã hội" use: the row that says when the section was last saved (with
    // "Khôi phục mặc định"; on phones the row shows only once something was saved and the button
    // moves into the "⋮" of the title row), one card per group and the page's own save bar. Each
    // image slot holds one picture from the Image library: "Đổi ảnh" / the empty box open it for that
    // slot, "Gỡ ảnh" empties it; the change is kept in the draft until saved. The share image is set
    // in "Google & mạng xã hội": its row here only leads there.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $label = 'text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong';
    $errorCount = count($errors->keys());
    $groups = ['brand' => [...WebsiteAppearanceSettings::SLOTS, 'share']];
    // The phone menu's tone and accent: only for a site that has a header setting (a phone menu to colour).
    if ($hasMenu) {
        $groups['menu'] = ['tone', 'accent'];
    }
    // Each choice: its options in the design's order (value → swatch, when it has one).
    $choices = [
        'tone' => ['auto' => null, 'light' => null, 'dark' => null],
        'accent' => ['neutral' => 'var(--color-text-muted)', 'brand' => $brandColor],
    ];
@endphp
<x-bs-admin::settings-shell active="appearance" :unsaved="$changes" :invalid="$errorCount" live-errors first-error="look-first-error">
    <x-slot:more>
        <x-bs-admin::dropdown align="end">
            <x-slot:trigger>
                <button type="button" class="hit-area inline-flex size-control items-center justify-center rounded-md text-text-muted hover:bg-surface-hover hover:text-text-strong"
                        title="{{ __('admin.set.more_aria', ['name' => __('admin.website_look')]) }}" aria-label="{{ __('admin.set.more_aria', ['name' => __('admin.website_look')]) }}" data-look-more>
                    <x-bs-admin::icon name="ri-more-2-fill" :class="$glyph" />
                </button>
            </x-slot:trigger>
            <button type="button" wire:click="restoreDefaults" x-on:click="open = false" class="w-full gap-2 px-3 text-start whitespace-nowrap text-text hover:bg-surface-hover">
                <x-bs-admin::icon name="ri-restart" :class="$glyph.' text-text-muted'" /><span>{{ __('admin.set.restore') }}</span>
            </button>
        </x-bs-admin::dropdown>
    </x-slot:more>

    <div class="@container/look flex flex-col gap-4" data-set-form
         x-data="{ open: 'brand' }"
         x-on:look-first-error.window="open = {{ Js::from($errors->has('tone') || $errors->has('accent') ? 'menu' : 'brand') }}; $nextTick(() => $el.querySelector('[data-ferr-focus]')?.focus())">

        {{-- When the section was last saved, and "Khôi phục mặc định" (in the "⋮" on phones). --}}
        <div @class(['flex flex-wrap items-center justify-between gap-3.5 border-b border-border pb-3.5', 'max-md:hidden' => $stamp === null]) data-look-stamp>
            <span class="inline-flex min-w-0 items-center gap-[7px] text-caption text-text-muted">
                <x-bs-admin::icon name="ri-time" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $stamp ?? __('admin.set.stamp_none') }}</span>
            </span>
            <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="restoreDefaults" wire:loading.attr="disabled" class="max-md:hidden" data-look-restore>
                <x-bs-admin::icon name="ri-restart" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" /><span>{{ __('admin.set.restore') }}</span>
            </x-bs-admin::button>
        </div>

        @if ($errorCount > 0)
            <x-bs-admin::alert color="danger" icon="ri-error-warning">{{ __('admin.gs.banner', ['count' => $errorCount]) }}</x-bs-admin::alert>
        @endif

        <div class="flex flex-col gap-3">
            @foreach ($groups as $group => $fields)
                @php
                    $bodyId = 'look-g-'.$group;
                @endphp
                <div class="overflow-hidden rounded-[9px] border bg-surface"
                     x-bind:class="open === {{ Js::from($group) }} ? 'border-primary-text/25 shadow-card' : 'border-border'">
                    <h2>
                        <button type="button" class="flex min-h-control-row w-full items-center gap-2.5 px-4 py-3 text-start hover:bg-surface-hover"
                                x-bind:class="open === {{ Js::from($group) }} && 'bg-primary-soft/60'"
                                x-on:click="open = open === {{ Js::from($group) }} ? null : {{ Js::from($group) }}"
                                x-bind:aria-expanded="open === {{ Js::from($group) }} ? 'true' : 'false'" aria-controls="{{ $bodyId }}">
                            <span class="inline-flex shrink-0 text-text-muted" x-show="open !== {{ Js::from($group) }}" x-cloak><x-bs-admin::icon name="ri-add" :class="$glyph" /></span>
                            <span class="inline-flex shrink-0 text-primary-text" x-show="open === {{ Js::from($group) }}"><x-bs-admin::icon name="ri-subtract" :class="$glyph" /></span>
                            <span class="min-w-0 flex-1 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.look.g.'.$group) }}</span>
                            @if ($errorCount > 0)
                                <span class="shrink-0 rounded-full bg-danger-soft px-2 py-0.5 text-caption font-bold whitespace-nowrap text-danger-text">{{ __('admin.gs.errs', ['count' => $errorCount]) }}</span>
                            @endif
                            <span class="shrink-0 text-caption whitespace-nowrap text-text-muted">{{ __('admin.gs.items', ['count' => count($fields)]) }}</span>
                        </button>
                    </h2>
                    <div id="{{ $bodyId }}" x-show="open === {{ Js::from($group) }}"
                         class="grid items-start gap-x-5 gap-y-4 border-t border-border px-4 pt-4 pb-[18px]">
                        @foreach ($fields as $key)
                            @php
                                $fid = 'look-'.$key;
                                $errId = $fid.'-err';
                            @endphp

                            @if (in_array($key, ['tone', 'accent'], true))
                                {{-- One choice of the phone menu: radio cards (design: choice field), the draft is kept on the server. --}}
                                @php
                                    $current = $key === 'tone' ? $tone : $accent;
                                    $bad = $errors->has($key);
                                @endphp
                                <div role="radiogroup" aria-labelledby="{{ $fid }}-l" aria-describedby="{{ trim(($bad ? $errId.' ' : '').$fid.'-hint') }}" class="flex min-w-0 flex-col gap-2" wire:key="look-f-{{ $key }}" data-look-choice="{{ $key }}">
                                    <span id="{{ $fid }}-l" class="{{ $label }}">{{ __('admin.look.f.'.$key) }}</span>
                                    <div class="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(min(100%,10.5rem),1fr))]">
                                        @foreach ($choices[$key] as $value => $swatch)
                                            @php
                                                $on = $current === $value;
                                            @endphp
                                            <label wire:key="look-o-{{ $key }}-{{ $value }}" @class([
                                                'flex min-h-control cursor-pointer items-start gap-2.5 rounded-md bg-surface px-3 py-2.5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-text',
                                                'border-2 border-primary-text bg-primary-soft/60' => $on,
                                                'border border-border-strong hover:border-primary-text' => ! $on,
                                                'border-danger-text' => $bad && ! $on,
                                            ])>
                                                <input type="radio" name="look-{{ $key }}" value="{{ $value }}" wire:model.live="{{ $key }}" @checked($on) class="sr-only" @if ($loop->first && $bad) data-ferr-focus @endif>
                                                @if ($on)
                                                    <x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph.' text-primary-text'" />
                                                @else
                                                    <span aria-hidden="true" class="{{ $glyph }} rounded-full border-2 border-border-strong"></span>
                                                @endif
                                                @if ($swatch !== null)
                                                    <span aria-hidden="true" class="size-[22px] shrink-0 rounded-sm border border-border-strong" style="background: {{ $swatch }}"></span>
                                                @endif
                                                <span class="flex min-w-0 flex-col gap-0.5">
                                                    <span class="text-[calc(0.84375rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.look.'.$key.'.'.$value) }}</span>
                                                    <span class="text-caption leading-normal text-text-muted">{{ __('admin.look.'.$key.'.'.$value.'_d') }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <span id="{{ $fid }}-hint" class="text-caption leading-normal text-text-muted">{{ __('admin.look.hint.'.$key) }}</span>
                                    @error($key)
                                        <span id="{{ $errId }}" data-ferr class="inline-flex items-start gap-[5px] text-caption text-danger-text">
                                            <x-bs-admin::icon name="ri-error-warning" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ $message }}</span>
                                        </span>
                                    @enderror
                                </div>
                                @continue
                            @endif

                            @if ($key === 'share')
                                {{-- The share image lives in "Google & mạng xã hội": this row only leads there. --}}
                                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 rounded-lg border border-border bg-surface-muted px-[15px] py-[13px]" wire:key="look-f-share" data-look-share>
                                    <div class="flex min-w-0 flex-[1_1_16rem] flex-col gap-0.5">
                                        <span class="text-[calc(0.84375rem+var(--user-text-boost))] font-medium text-text-strong">{{ __('admin.look.f.share') }}</span>
                                        <span class="text-caption leading-normal text-text-muted">{{ __('admin.look.hint.share') }}</span>
                                    </div>
                                    <x-bs-admin::button variant="outline" color="secondary" :href="route('admin.settings.seo')" class="shrink-0">
                                        <x-bs-admin::icon name="ri-arrow-right" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0" /><span>{{ __('admin.look.share_open') }}</span>
                                    </x-bs-admin::button>
                                </div>
                                @continue
                            @endif

                            @php
                                $picture = $pictures[$key];
                                $name = __('admin.look.f.'.$key);
                                $bad = $errors->has('images.'.$key);
                            @endphp
                            <div class="flex min-w-0 flex-col gap-1.5" wire:key="look-f-{{ $key }}" data-look-slot="{{ $key }}">
                                <span id="{{ $fid }}-l" class="{{ $label }}">{{ $name }}</span>
                                @if ($picture !== null)
                                    <div role="group" aria-labelledby="{{ $fid }}-l" class="flex flex-wrap items-center gap-3.5 rounded-lg border bg-surface p-3 {{ $bad ? 'border-danger-text' : 'border-border' }}"
                                         @if ($bad && ! $canPick) tabindex="-1" data-ferr-focus aria-describedby="{{ $errId }}" @endif>
                                        @if ($picture['thumb'] !== null)
                                            <img src="{{ $picture['thumb'] }}" alt="" class="size-[58px] shrink-0 rounded-md border border-border bg-surface-muted object-contain" loading="lazy" decoding="async">
                                        @else
                                            <span class="inline-flex size-[58px] shrink-0 items-center justify-center rounded-md border border-border bg-surface-muted text-text-muted"><x-bs-admin::icon name="ri-image-edit" class="size-6" /></span>
                                        @endif
                                        <div class="flex min-w-[8rem] flex-1 flex-col gap-0.5">
                                            <span class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong [overflow-wrap:anywhere]">{{ $picture['name'] }}</span>
                                            <span class="text-caption text-text-muted">{{ $picture['meta'] }}</span>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-[7px]">
                                            @if ($canPick)
                                                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="pickFor({{ Js::from($key) }})" data-look-change
                                                                    :data-ferr-focus="$bad" :aria-describedby="trim(($bad ? $errId.' ' : '').$fid.'-hint')">{{ __('admin.seoset.share_change') }}</x-bs-admin::button>
                                            @endif
                                            <x-bs-admin::tooltip :text="__('admin.seoset.share_remove')">
                                                <x-bs-admin::button size="sm" variant="outline" color="secondary" icon wire:click="removeImage({{ Js::from($key) }})" :aria-label="__('admin.look.remove_aria', ['name' => $name])" data-look-remove>
                                                    <x-bs-admin::icon name="ri-delete-bin-6" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                                </x-bs-admin::button>
                                            </x-bs-admin::tooltip>
                                        </div>
                                    </div>
                                @elseif ($canPick)
                                    <button type="button" wire:click="pickFor({{ Js::from($key) }})" data-look-change aria-labelledby="{{ $fid }}-l {{ $fid }}-pick"
                                            @if ($bad) data-ferr-focus @endif aria-describedby="{{ trim(($bad ? $errId.' ' : '').$fid.'-hint') }}"
                                            class="flex min-h-control w-full items-center gap-3.5 rounded-lg border border-dashed bg-surface p-4 text-start hover:border-primary-text {{ $bad ? 'border-danger-text' : 'border-border-strong' }}">
                                        <x-bs-admin::icon name="ri-image-add" class="size-6 shrink-0 text-text-muted" />
                                        <span id="{{ $fid }}-pick" class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.seoset.share_pick') }}</span>
                                    </button>
                                @else
                                    <div class="flex items-center gap-3.5 rounded-lg border border-dashed border-border-strong bg-surface-muted p-4"
                                         @if ($bad) tabindex="-1" data-ferr-focus aria-describedby="{{ $errId }}" @endif>
                                        <x-bs-admin::icon name="ri-image-add" class="size-6 shrink-0 text-text-muted" />
                                        <span class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.look.none') }}</span>
                                    </div>
                                @endif
                                <span id="{{ $fid }}-hint" class="text-caption leading-normal text-text-muted">{{ __('admin.look.hint.'.$key) }}</span>
                                @error('images.'.$key)
                                    <span id="{{ $errId }}" data-ferr class="inline-flex items-start gap-[5px] text-caption text-danger-text">
                                        <x-bs-admin::icon name="ri-error-warning" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ $message }}</span>
                                    </span>
                                @enderror
                            </div>
                        @endforeach
                        @error('images')
                            <span id="look-images-err" data-ferr data-ferr-focus tabindex="-1" class="inline-flex items-start gap-[5px] text-caption text-danger-text">
                                <x-bs-admin::icon name="ri-error-warning" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ $message }}</span>
                            </span>
                        @enderror
                    </div>
                </div>
            @endforeach
        </div>

    </div>

    {{-- The shared Image library, opened by an image slot (one picture goes back). --}}
    @include('admin::partials.media-picker-modal', ['pickedIds' => []])

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" :undo="$undo !== null ? 'undoRestore' : null" />
</x-bs-admin::settings-shell>
