{{-- «Thời hạn lưu giữ liên hệ»: how long the website keeps a lead before the nightly purge deletes it
     for good (LeadRetention). The pick stays in the browser until «Lưu thời hạn» sends it; the law
     warning follows the pick as it changes, without asking the server. The chosen option takes the
     focus when it opens (a radio group's own rule: Space never changes the pick by surprise), a frame
     after the dialog's own first-control focus. --}}
@php
    $choices = array_map(\App\Modules\Forms\Support\LeadRetention::key(...), \App\Modules\Forms\Support\LeadRetention::CHOICES);
    $hint = 'm-0 text-small text-text-muted [overflow-wrap:anywhere] [text-wrap:pretty]';
@endphp
<x-bs-admin::modal model="retentionOpen" :title="__('leads.ret_title')" :close-label="__('admin.close')" size="md"
    wire:loading.attr="aria-busy" wire:target="saveRetention">
    <div class="flex flex-col gap-3.5"
         x-init="$wire.$watch('retentionOpen', (open) => { if (open) { $nextTick(() => requestAnimationFrame(() => (document.querySelector('input[name=lr-months]:checked') ?? document.getElementById('lr-opt-12'))?.focus())) } })">
        <p class="m-0 [text-wrap:pretty]">{{ __('leads.ret_text') }}</p>

        <div class="flex flex-col gap-2" role="radiogroup" aria-label="{{ __('leads.ret_group') }}"
             @if ($retentionError !== '') aria-describedby="lr-err" @endif>
            @foreach ($choices as $key)
                <label wire:key="lr-{{ $key }}"
                       class="flex min-h-hit cursor-pointer items-start gap-2.5 rounded-md border border-border-strong px-3 py-2.5 has-checked:border-primary-text has-checked:bg-primary-text/6">
                    <input id="lr-opt-{{ $key }}" type="radio" name="lr-months" value="{{ $key }}" wire:model="retentionPick"
                           class="mt-0.5 size-4.5 shrink-0 accent-primary">
                    <span class="min-w-0 [overflow-wrap:anywhere]">
                        <b class="font-semibold text-text-strong">{{ __('leads.ret_opt_'.$key) }}</b><br>
                        <span class="text-small text-text-muted">{{ __('leads.ret_opt_'.$key.'_hint') }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        <div x-show="$wire.retentionPick === 'none'" @style(['display: none' => $retentionPick !== 'none'])>
            <x-bs-admin::alert color="warning" role="status"><span><b>{{ __('leads.ret_none_warn_1') }}</b> {{ __('leads.ret_none_warn_2') }}</span></x-bs-admin::alert>
        </div>

        @if ($retentionError !== '')
            <x-bs-admin::field-error id="lr-err" class="font-medium [overflow-wrap:anywhere]">{{ $retentionError }}</x-bs-admin::field-error>
        @endif

        <p class="{{ $hint }} flex items-start gap-1"><x-bs-admin::icon name="ri-spam-2" class="mt-[0.25em] size-[1em] shrink-0" /><span>{{ __('leads.ret_spam') }}</span></p>
    </div>

    <x-slot:footer>
        <x-bs-admin::button variant="outline" color="secondary" wire:click="cancelRetention">{{ __('admin.cancel') }}</x-bs-admin::button>
        <x-bs-admin::button color="primary" wire:click="saveRetention" wire:loading.attr="aria-disabled" wire:target="saveRetention">
            <x-bs-admin::icon name="ri-save-3" class="size-4" /> {{ __('leads.ret_save') }}
        </x-bs-admin::button>
    </x-slot:footer>
</x-bs-admin::modal>
