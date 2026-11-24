@php
    // The website face's own app for one provider, folded away («Nâng cao — dùng ứng dụng riêng của
    // bạn»): which app serves now, the two keys and «Dùng khóa riêng» / «Về ứng dụng chung», each at
    // once. The keys live in the browser part (admin-social.js) until «Dùng khóa riêng».
    $cfg = [
        'provider' => $key,
        'id' => $app['client_id'],
        'saved' => (bool) $app['secret_set'],
        't' => [
            'idInvalid' => __('admin.sl.'.$key.'.id_invalid'),
            'secretInvalid' => __('admin.sl.'.$key.'.secret_invalid'),
        ],
    ];
    $sm = 'size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0';
    $label = 'text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text-strong';
@endphp
<p class="mx-1 mb-1.5 text-caption text-text-muted">{{ $app['own_ready'] ? __('admin.sl.mode_own') : __('admin.sl.mode_shared') }}</p>
<details wire:ignore.self class="group mx-1 mb-2.5 rounded-md border border-border bg-surface px-3.5 pt-1.5 pb-2.5" @if (! $app['opened']) open @endif>
    <summary class="hit-area flex min-h-control cursor-pointer list-none items-center gap-1.5 text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-primary-text [&::-webkit-details-marker]:hidden">
        <x-bs-admin::icon name="ri-arrow-right-s" class="{{ $sm }} transition-transform group-open:rotate-90 motion-reduce:transition-none" />{{ __('admin.sl.adv_title') }}
    </summary>
    <div x-ignore wire:ignore.self data-bs-lazy="bsSocialKeys" x-data="bsSocialKeys({{ \Illuminate\Support\Js::from($cfg) }})">
        <p class="mt-1 mb-2.5 text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted">{{ __('admin.sl.adv_desc', ['name' => $name]) }}</p>
        <p data-bs-lazy-wait x-show="false" class="text-caption text-text-muted">{{ __('admin.sl.loading') }}</p>
        <x-bs-admin::alert color="danger" icon="ri-wifi-off" data-bs-lazy-error x-show="false">
            <div class="flex flex-wrap items-center gap-2.5">
                {{ __('admin.sl.load_failed') }}
                <x-bs-admin::button size="sm" variant="outline" color="primary" data-bs-lazy-retry><x-bs-admin::icon name="ri-refresh" class="{{ $sm }}" />{{ __('admin.sl.retry') }}</x-bs-admin::button>
            </div>
        </x-bs-admin::alert>
        <div x-cloak>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,15rem),1fr))] gap-2.5">
                <div class="flex flex-col gap-1.5">
                    <label for="sl-own-id-{{ $key }}" class="{{ $label }}">{{ __('admin.sl.own_id') }}</label>
                    <input id="sl-own-id-{{ $key }}" x-ref="id" type="text" class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text" x-bind:value="id" x-on:input="typedId($event.target.value)"
                           x-bind:aria-invalid="idError ? 'true' : 'false'" placeholder="{{ __('admin.sl.'.$key.'.id_ph') }}" autocomplete="off" spellcheck="false" value="{{ $app['client_id'] }}">
                    <x-bs-admin::field-error x-show="idError"><span x-text="idError"></span></x-bs-admin::field-error>
                </div>
                <div class="flex flex-col gap-1.5">
                    <span class="{{ $label }}"><label for="sl-own-sec-{{ $key }}" x-show="editingSecret">{{ __('admin.sl.own_secret') }}</label><span x-show="! editingSecret">{{ __('admin.sl.own_secret') }}</span></span>
                    <span x-show="! editingSecret" class="flex min-h-control flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-caption font-semibold text-success-text"><x-bs-admin::icon name="ri-lock" class="{{ $sm }}" />{{ __('admin.sl.secret_saved') }}</span>
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="replace()" :aria-label="__('admin.sl.replace_aria', ['label' => __('admin.sl.own_secret')])">{{ __('admin.sl.replace') }}</x-bs-admin::button>
                    </span>
                    <span x-show="editingSecret" class="flex flex-col gap-1.5">
                        <input id="sl-own-sec-{{ $key }}" x-ref="secret" type="password" class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text" x-bind:value="secret" x-on:input="typedSecret($event.target.value)"
                               x-bind:aria-invalid="secretError ? 'true' : 'false'" placeholder="{{ __('admin.sl.'.$key.'.secret_ph') }}" autocomplete="new-password" spellcheck="false">
                        <span x-show="saved" class="text-caption text-text-muted">{{ __('admin.sl.keep_blank') }}</span>
                    </span>
                    <x-bs-admin::field-error x-show="secretError"><span x-text="secretError"></span></x-bs-admin::field-error>
                </div>
            </div>
            <div class="mt-2.5 flex flex-wrap gap-2">
                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="own()"><x-bs-admin::icon name="ri-save-3" class="{{ $sm }}" />{{ __('admin.sl.use_own') }}</x-bs-admin::button>
                @if ($app['own'])
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="useShared('{{ $key }}')"><x-bs-admin::icon name="ri-arrow-go-back" class="{{ $sm }}" />{{ __('admin.sl.use_shared') }}</x-bs-admin::button>
                @endif
            </div>
        </div>
    </div>
</details>
