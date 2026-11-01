{{--
    The TXT row that proves a contested name is this site's (state "claim"): its name and value,
    each with [Sao chép]. Another site is only ever "a website khác" — never named.
    In: $group, $proof (name, value), $dialog (bool).
--}}
<div class="flex min-w-0 basis-full flex-col gap-2.5 rounded-md border border-border bg-surface p-3" role="group" aria-label="{{ __('admin.domains_claim_aria') }}">
    <span class="font-semibold text-text-strong">{{ $dialog ? __('admin.domains_claim_dlg_title') : __('admin.domains_claim_title') }}</span>
    <span class="text-small text-text-muted">{{ $dialog ? __('admin.domains_claim_dlg_body') : __('admin.domains_claim_body') }}</span>
    <x-bs-admin::copy-field :label="__('admin.domains_claim_name')" :value="$proof['name']" copied="copied" :args="['txt_name', $group->name]" />
    <x-bs-admin::copy-field :label="__('admin.domains_claim_value')" :value="$proof['value']" copied="copied" :args="['txt_value', $group->name]" />
    <span class="text-caption text-text-muted">{{ $dialog ? __('admin.domains_claim_dlg_type') : __('admin.domains_claim_type_hint') }}</span>
</div>
