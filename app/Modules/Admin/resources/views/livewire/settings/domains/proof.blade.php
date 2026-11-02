{{--
    The TXT rows that prove a contested name is this site's: each with its name and value and
    [Sao chép]. The proof is per hostname, so when the other spelling (with or without www) is held
    by another site as well it gets its own pair, labelled for that spelling, with one sentence
    saying both lines are needed. When only the other spelling is held elsewhere (the running one is
    proved, waits, or was lost), only its pair is shown, with the sentence that names "Kiểm tra lại"
    and that button beside it (design 21, scenario disputeHalf). The add dialog has no such button,
    so it shows the pair without that sentence. Another site is only ever "a website khác" — never
    named.
    In: $group, $proof (name, value), $twinProof (name, value, or null), $dialog (bool),
        $manage (bool: the reader may check the name), $locked (bool).
--}}
@php
    $first = $group->state === 'claim';
    $alt = \App\Modules\Tenancy\Domains\DomainName::display((string) $group->alt);
@endphp
<div class="flex min-w-0 basis-full flex-col gap-2.5 rounded-md border border-border bg-surface p-3" role="group" aria-label="{{ __('admin.domains_claim_aria') }}">
    <span class="font-semibold text-text-strong">{{ $dialog ? __('admin.domains_claim_dlg_title') : __('admin.domains_claim_title') }}</span>
    <span class="text-small text-text-muted">{{ $dialog ? __('admin.domains_claim_dlg_body') : __('admin.domains_claim_body') }}</span>
    @if ($first)
        <x-bs-admin::copy-field :label="__('admin.domains_claim_name')" :value="$proof['name']" copied="copied" :args="['txt_name', $group->name]" />
        <x-bs-admin::copy-field :label="__('admin.domains_claim_value')" :value="$proof['value']" copied="copied" :args="['txt_value', $group->name]" />
    @endif
    @if ($twinProof)
        @if ($first)
            <span class="text-small text-text-muted">{{ __('admin.domains_claim_twin_why', ['alt' => $alt]) }}</span>
        @elseif (! $dialog)
            <span class="text-small text-text-muted">{{ __('admin.domains_claim_twin_only', ['alt' => $alt]) }}</span>
        @endif
        <x-bs-admin::copy-field :label="__('admin.domains_claim_name')" :value="$twinProof['name']" copied="copied" :args="['txt2_name', $group->name]" />
        <x-bs-admin::copy-field :label="__('admin.domains_claim_value')" :value="$twinProof['value']" copied="copied" :args="['txt2_value', $group->name]" />
        @if (! $dialog && $manage && $group->altClaimOnly())
            <div>
                <x-bs-admin::button variant="outline" color="primary" size="sm" wire:click="check({{ \Illuminate\Support\Js::from($group->name) }})"
                    wire:loading.attr="disabled" wire:target="check({{ \Illuminate\Support\Js::from($group->name) }})" data-check-alt
                    :aria-disabled="$locked ? 'true' : null">
                    <x-bs-admin::icon name="ri-radar" class="size-[calc(1rem+var(--user-text-boost))]" />
                    <span>{{ __('admin.domains_next_recheck') }}</span>
                </x-bs-admin::button>
            </div>
        @endif
    @endif
    <span class="text-caption text-text-muted">{{ $dialog ? __('admin.domains_claim_dlg_type') : __('admin.domains_claim_type_hint') }}</span>
</div>
