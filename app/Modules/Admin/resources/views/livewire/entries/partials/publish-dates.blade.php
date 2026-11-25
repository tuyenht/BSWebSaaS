{{-- The «Đăng» card's dates (board 07). A post's «Ngày đăng» line with «Đổi ngày hiện», which opens the day it shows
     as published (not after today in the website's clock; empty = the real day; Editor::$displayDate, every language's),
     and «Gỡ khỏi website lúc» for every entry but the Home page (Editor::$expiresAt, written by ChangeEntryExpiry after
     the save; empty = it stays). «Dùng ngày đăng thật» and «Bỏ giờ gỡ» empty the field and offer «Hoàn tác». --}}
@if ($pdLine !== null)
    <div class="flex flex-col gap-2" data-entry-pd x-data="{ pd: {{ $errors->has('displayDate') ? 'true' : 'false' }} }">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <span class="text-small leading-[1.45] text-text [overflow-wrap:anywhere]">{{ $pdLine }}</span>
            <x-bs-admin::button variant="outline" color="secondary" size="sm" aria-controls="entry-pd-box" x-bind:aria-expanded="pd ? 'true' : 'false'"
                                x-on:click="pd = ! pd; if (pd) { $nextTick(() => $refs.pd.focus()) }">
                <span x-show="! pd" class="inline-flex"><x-bs-admin::icon name="ri-calendar" :class="$glyph" /></span><span x-show="pd" x-cloak class="inline-flex"><x-bs-admin::icon name="ri-arrow-up-s" :class="$glyph" /></span>
                {{ __('entries.ed.pd_change') }}
            </x-bs-admin::button>
        </div>
        <div id="entry-pd-box" class="flex flex-col gap-1.5" x-show="pd" x-cloak>
            <label for="entry-pd" class="{{ $label }}">{{ __('entries.ed.pd_label_card', ['doc' => __('entries.ed.doc_post')]) }}</label>
            <input type="date" id="entry-pd" x-ref="pd" wire:model.blur="displayDate" max="{{ app(\App\Modules\Tenancy\Context\SiteTime::class)->now()->format('Y-m-d') }}"
                   @readonly($sharedLock) @if ($sharedLock) aria-readonly="true" title="{{ $sharedWhy }}" @endif
                   aria-describedby="@error('displayDate') entry-pd-e @enderror entry-pd-h" @error('displayDate') aria-invalid="true" @enderror
                   class="field {{ $fieldLook }}">
            @error('displayDate') <x-bs-admin::field-error id="entry-pd-e">{{ $message }}</x-bs-admin::field-error> @enderror
            <p id="entry-pd-h" class="m-0 text-caption text-text-muted [overflow-wrap:anywhere]">{{ __('entries.ed.pd_hint_card', ['doc' => __('entries.ed.doc_post')]) }}</p>
            @if ($displayDate !== '' && ! $sharedLock)
                <div>
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="useRealDate" x-on:click="$nextTick(() => $refs.pd.focus())">
                        <x-bs-admin::icon name="ri-arrow-go-back" :class="$glyph" /> {{ __('entries.ed.pd_use_real') }}
                    </x-bs-admin::button>
                </div>
            @endif
        </div>
    </div>
@endif
@if ($expiry !== null)
    <div class="flex flex-col gap-1.5" data-entry-exp>
        <label for="entry-exp-at" class="{{ $label }}">{{ __('entries.ed.exp_label') }} <span class="font-normal text-text-muted">{{ $expiry['zone'] }}</span></label>
        <input type="datetime-local" id="entry-exp-at" wire:model.blur="expiresAt"
               @readonly(! $expiry['editable']) @if (! $expiry['editable']) aria-readonly="true" @if ($sharedLock) title="{{ $sharedWhy }}" @endif @endif
               aria-describedby="@error('expiresAt') entry-exp-e @enderror entry-exp-h" @error('expiresAt') aria-invalid="true" @enderror
               class="field {{ $fieldLook }}">
        @error('expiresAt') <x-bs-admin::field-error id="entry-exp-e">{{ $message }}</x-bs-admin::field-error> @enderror
        <p id="entry-exp-h" class="m-0 text-caption text-text-muted [overflow-wrap:anywhere]">{{ $expiry['hint'] }}</p>
        @if ($expiresAt !== '' && $expiry['editable'])
            <div>
                <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="clearExpiry" x-on:click="$nextTick(() => document.getElementById('entry-exp-at')?.focus())">
                    <x-bs-admin::icon name="ri-close" :class="$glyph" /> {{ __('entries.ed.exp_clear') }}
                </x-bs-admin::button>
            </div>
        @endif
    </div>
@endif
