@props([
    'changes' => 0,
    'invalid' => 0,
    'live' => false,
    'save' => 'save',
    'discard' => 'resetChanges',
    'firstError' => null,
    'firstErrorDetail' => [],
])
@php
    // The one save bar of a settings section (design 01, the bar stuck to the bottom of "Cài đặt"),
    // rendered by the settings shell under the section, across the whole settings area and outside
    // its cards. `changes` is the section's count of fields that differ from the saved values,
    // `invalid` its count of fields in error; with `live` the section checks fields as they are left,
    // so "Lưu" takes the member to the first error instead of saving while one is left. `firstError`
    // names the page's window event that opens the group holding it (with `firstErrorDetail`);
    // without one the bar focuses the first field marked aria-invalid. Behaviour:
    // resources/js/admin-save-bar.js (Alpine.data 'bsSaveBar', started by the shared lazy loader).
    // Livewire redraws the hidden state element only; the status and the buttons belong to the
    // browser once the part has started (typed text the server has not seen counts as a change), so
    // the server writes only the sentence that holds now and the part writes the others (the part's
    // sentences go to it with their letters escaped, so the page's text holds only the one shown).
    $changes = max(0, (int) $changes);
    $invalid = max(0, (int) $invalid);
    $clean = $changes === 0;
    $blocked = $clean || ($live && $invalid > 0);
    $config = [
        'save' => $save,
        'discard' => $discard,
        'live' => (bool) $live,
        'firstError' => $firstError,
        'changes' => __('admin.savebar.changes', ['count' => ':count']),
        'clean' => __('admin.savebar.clean'),
        'savedAt' => __('admin.savebar.saved_at', ['time' => ':time']),
        'errs' => __('admin.savebar.errs', ['count' => ':count']),
    ];
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $stat = 'sb-st-'.substr(md5($save.'|'.$discard), 0, 6);
@endphp
<div x-ignore wire:ignore.self data-bs-lazy="bsSaveBar" x-data="bsSaveBar({{ json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) }})" data-set-savebar
     role="region" aria-label="{{ __('admin.savebar.region') }}"
     class="sticky bottom-0 z-20 -mb-4 mt-4 flex flex-wrap items-center gap-x-3.5 gap-y-2.5 border-t border-border bg-surface pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] lg:-mb-6">
    <span hidden x-ref="state" data-sb-state data-changes="{{ $changes }}" data-invalid="{{ $invalid }}"
          data-first-error="{{ json_encode((object) $firstErrorDetail) }}" @if ($clean) data-clean @endif></span>

    <div wire:ignore id="{{ $stat }}" role="status" aria-live="polite"
         class="flex min-w-0 flex-auto flex-wrap items-center gap-x-3 gap-y-1 text-[calc(0.875rem+var(--user-text-boost))] leading-snug">
        <span class="inline-flex items-center gap-1.5 font-semibold text-warning-text" x-show="dirty" @if ($clean) x-cloak @endif data-sb-changes>
            <x-bs-admin::icon name="ri-error-warning" :class="$glyph" /><span x-text="changesText">@unless ($clean){{ __('admin.savebar.changes', ['count' => $changes]) }}@endunless</span>
        </span>
        <span class="inline-flex items-center gap-1.5 text-text-muted" x-show="! dirty" @unless ($clean) x-cloak @endunless data-sb-clean>
            <x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph" /><span x-text="cleanText">@if ($clean){{ __('admin.savebar.clean') }}@endif</span>
        </span>
        <span class="inline-flex items-center gap-1.5 font-semibold text-danger-text" x-show="invalid > 0" @if ($invalid === 0) x-cloak @endif data-sb-errs>
            <x-bs-admin::icon name="ri-close-circle" :class="$glyph" /><span x-text="errsText">@if ($invalid > 0){{ __('admin.savebar.errs', ['count' => $invalid]) }}@endif</span>
        </span>
        <button type="button" class="hit-area-row font-semibold text-danger-text underline underline-offset-2"
                x-show="invalid > 0" @if ($invalid === 0) x-cloak @endif x-on:click="firstError()">{{ __('admin.savebar.first_error') }}</button>
    </div>

    <div wire:ignore class="ms-auto flex flex-wrap items-center justify-end gap-2.5">
        <x-bs-admin::button variant="outline" color="secondary" data-sb-discard aria-describedby="{{ $stat }}"
                            :disabled="$clean" x-bind:disabled="! dirty || busy !== ''" x-on:click="discard()">{{ __('admin.savebar.discard') }}</x-bs-admin::button>
        <x-bs-admin::button color="primary" data-sb-save aria-describedby="{{ $stat }}"
                            aria-disabled="{{ $blocked ? 'true' : 'false' }}" x-bind:aria-disabled="blocked ? 'true' : 'false'"
                            aria-busy="false" x-bind:aria-busy="busy === 'save' ? 'true' : 'false'" x-on:click="go()"
                            class="aria-busy:cursor-progress aria-disabled:cursor-not-allowed aria-disabled:bg-border aria-disabled:text-text-muted aria-disabled:shadow-none aria-disabled:hover:brightness-100">
            <span class="contents" x-show="busy !== 'save'"><x-bs-admin::icon name="ri-save-3" :class="$glyph" /></span>
            <span class="contents" x-show="busy === 'save'" x-cloak><x-bs-admin::icon name="ri-loader-4" :class="$glyph.' motion-safe:animate-spin'" /></span>
            <span x-text="busy === 'save' ? @js(__('admin.savebar.saving')) : @js(__('admin.savebar.save'))">{{ __('admin.savebar.save') }}</span>
        </x-bs-admin::button>
    </div>
</div>
