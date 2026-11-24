{{-- «Tải danh sách về máy»: the file holds visitors' personal data, so the member types their password
     (or their authenticator code) again. What they type stays in the browser until «Tải tệp về máy»
     sends it as the call's argument; the server answers with the reason it opened no file
     ($exportError), or sends the browser to the download. An empty field or a code that is not 6
     digits is said as the field is left, without asking the server. --}}
@php
    $lab = 'mb-1.5 flex flex-wrap items-center gap-1 text-small font-semibold text-text-strong';
    $hint = 'm-0 text-small text-text-muted [overflow-wrap:anywhere] [text-wrap:pretty]';
@endphp
<x-bs-admin::modal model="exportOpen" :title="__('leads.export')" :close-label="__('admin.close')" size="md"
    wire:loading.attr="aria-busy" wire:target="confirmExport">
    <div class="flex flex-col gap-3.5"
         x-data="{
            secret: '', show: false, touched: false, edited: false, busy: false,
            get byCode() { return $wire.exportByCode },
            client() {
                if (this.byCode) { return /^\d{6}$/.test(this.secret.replace(/\s/g, '')) ? '' : {{ Js::from(__('leads.export_code_format')) }} }
                return this.secret === '' ? {{ Js::from(__('leads.export_pw_required')) }} : ''
            },
            err() { const s = $wire.exportError; return (! this.edited && s) ? s : (this.touched ? this.client() : '') },
            field() { return document.getElementById(this.byCode ? 'lx-code' : 'lx-pw') },
            mode(code) { this.secret = ''; this.touched = false; this.edited = false; this.show = false; $wire.useExportCode(code).then(() => this.$nextTick(() => this.field()?.focus())) },
            submit() {
                if (this.busy) { return }
                this.touched = true;
                if (this.client() !== '') { this.edited = true; this.field()?.focus(); return }
                this.busy = true; this.edited = false;
                $wire.confirmExport(this.secret).then(() => { this.busy = false; if ($wire.exportError) { this.$nextTick(() => this.field()?.focus()) } else { this.secret = ''; this.touched = false } }, () => { this.busy = false })
            },
         }"
         x-init="$wire.$watch('exportOpen', (open) => { if (open) { secret = ''; touched = false; edited = false; show = false; busy = false } })"
         x-on:lx-submit.window="submit()">
        <x-bs-admin::alert color="warning">{{ __('leads.export_warn', ['count' => $exportN]) }}</x-bs-admin::alert>
        <p class="m-0 [text-wrap:pretty]">{{ __('leads.export_text', ['count' => $exportN]) }}</p>

        @if (! $exportByCode)
            <div>
                <label class="{{ $lab }}" for="lx-pw">{{ __('leads.export_pw') }} <span class="font-bold text-danger-text" aria-hidden="true">*</span></label>
                <div class="flex gap-2">
                    <input id="lx-pw" class="field block w-full min-w-0 rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text flex-auto" type="password" x-bind:type="show ? 'text' : 'password'" autocomplete="current-password" autofocus required
                           x-model="secret" x-on:input="edited = true" x-on:blur="touched = true" x-on:keydown.enter.prevent="submit()"
                           x-bind:aria-invalid="err() ? 'true' : 'false'" aria-describedby="lx-pw-e lx-pw-h"
                           @if ($exportError !== '') aria-invalid="true" @endif>
                    <x-bs-admin::button variant="outline" color="neutral" class="shrink-0" x-on:click="show = ! show" x-bind:aria-pressed="show ? 'true' : 'false'" aria-pressed="false" :aria-label="__('leads.export_show_aria')">
                        <x-bs-admin::icon name="ri-eye" class="size-4" x-show="! show" />
                        <x-bs-admin::icon name="ri-eye-off" class="size-4" x-show="show" x-cloak />
                        <span x-text="show ? {{ Js::from(__('leads.export_hide')) }} : {{ Js::from(__('leads.export_show')) }}">{{ __('leads.export_show') }}</span>
                    </x-bs-admin::button>
                </div>
                <x-bs-admin::field-error id="lx-pw-e" class="mt-1.5 font-medium [overflow-wrap:anywhere]" x-show="err() !== ''" :x-cloak="$exportError === ''"><span x-text="err()">{{ $exportError }}</span></x-bs-admin::field-error>
                <p id="lx-pw-h" class="{{ $hint }} mt-1.5">{{ __('leads.export_pw_hint') }}</p>
            </div>
            @if ($canCode)
                <x-bs-admin::button size="sm" variant="ghost" color="primary" class="self-start" x-on:click="mode(true)">{{ __('leads.export_use_code') }}</x-bs-admin::button>
            @endif
        @else
            <div>
                <label class="{{ $lab }}" for="lx-code">{{ __('leads.export_code') }} <span class="font-bold text-danger-text" aria-hidden="true">*</span></label>
                <input id="lx-code" class="field block w-full min-w-0 rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text max-w-[220px]" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" autofocus required
                       x-model="secret" x-on:input="edited = true" x-on:blur="touched = true" x-on:keydown.enter.prevent="submit()"
                       x-bind:aria-invalid="err() ? 'true' : 'false'" aria-describedby="lx-code-e"
                       @if ($exportError !== '') aria-invalid="true" @endif>
                <x-bs-admin::field-error id="lx-code-e" class="mt-1.5 font-medium [overflow-wrap:anywhere]" x-show="err() !== ''" :x-cloak="$exportError === ''"><span x-text="err()">{{ $exportError }}</span></x-bs-admin::field-error>
            </div>
            <x-bs-admin::button size="sm" variant="ghost" color="primary" class="self-start" x-on:click="mode(false)">{{ __('leads.export_use_pw') }}</x-bs-admin::button>
        @endif

        <p class="{{ $hint }} flex items-start gap-1.5"><x-bs-admin::icon name="ri-file-list-3" class="mt-0.5 size-4 shrink-0" /><span>{{ __('leads.export_logged') }}</span></p>
    </div>

    <x-slot:footer>
        <x-bs-admin::button variant="outline" color="secondary" wire:click="cancelExport">{{ __('admin.cancel') }}</x-bs-admin::button>
        <x-bs-admin::button color="primary" x-on:click="$dispatch('lx-submit')" wire:loading.attr="aria-disabled" wire:target="confirmExport">
            <span wire:loading.remove wire:target="confirmExport" class="inline-flex items-center gap-1.5"><x-bs-admin::icon name="ri-download-2" class="size-4" /> {{ __('leads.export_ok', ['count' => $exportN]) }}</span>
            <span wire:loading wire:target="confirmExport" class="inline-flex items-center gap-1.5"><x-bs-admin::icon name="ri-loader-4" class="size-4 motion-safe:animate-spin" /> {{ __('leads.export_busy') }}</span>
        </x-bs-admin::button>
    </x-slot:footer>
</x-bs-admin::modal>
