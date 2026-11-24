@php
    // The platform face's «Cấu hình» panel for one provider: the guide (steps, values to copy into the
    // provider's console, quick paste), the two keys, «Kiểm tra & lưu» / «Ngắt kết nối», and the
    // common problems. The keys live in the browser part (admin-social.js) until «Kiểm tra & lưu».
    $p = static fn (string $k, array $r = []): string => __('admin.sl.'.$key.'.'.$k, $r);
    $idLabel = $p('id_label');
    $secretLabel = $p('secret_label');
    $cfg = [
        'provider' => $key,
        'id' => $app['client_id'],
        'saved' => (bool) $app['connected'],
        'rows' => array_map(static fn (array $r): array => ['label' => $r['label'], 'value' => $r['value']], $rows),
        't' => [
            'idInvalid' => __('admin.sl.'.$key.'.id_invalid'),
            'secretInvalid' => __('admin.sl.'.$key.'.secret_invalid'),
            'pasteBoth' => __('admin.sl.paste_both', ['id' => $idLabel, 'secret' => $secretLabel]),
            'pasteOne' => __('admin.sl.paste_one'),
            'pasteNone' => __('admin.sl.paste_none'),
            'idLabel' => $idLabel,
            'secretLabel' => $secretLabel,
        ],
    ];
    $sm = 'size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0';
    $blkHead = 'bg-surface-muted px-3.5 py-2.5 text-caption font-bold uppercase leading-snug tracking-[.04em] text-text-muted';
    $label = 'flex flex-wrap items-center gap-1 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text-strong';
@endphp
<div x-ignore wire:ignore.self data-bs-lazy="bsSocialKeys" x-data="bsSocialKeys({{ \Illuminate\Support\Js::from($cfg) }})">
    <p data-bs-lazy-wait x-show="false" class="text-caption text-text-muted">{{ __('admin.sl.loading') }}</p>
    <x-bs-admin::alert color="danger" icon="ri-wifi-off" data-bs-lazy-error x-show="false">
        <div class="flex flex-wrap items-center gap-2.5">
            {{ __('admin.sl.load_failed') }}
            <x-bs-admin::button size="sm" variant="outline" color="primary" data-bs-lazy-retry><x-bs-admin::icon name="ri-refresh" class="{{ $sm }}" />{{ __('admin.sl.retry') }}</x-bs-admin::button>
        </div>
    </x-bs-admin::alert>

    <div x-cloak>
        <div class="mb-3.5 flex flex-wrap items-center gap-2.5">
            <span class="flex items-center gap-1 text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">
                {{ __('admin.sl.intro') }}
                <x-bs-admin::help-pop :label="__('admin.sl.explain_aria')">{{ __('admin.sl.intro_help', ['console' => $p('console')]) }}</x-bs-admin::help-pop>
            </span>
            <span class="flex-1"></span>
            <x-bs-admin::button size="sm" variant="outline" color="secondary" :href="$console" :navigate="false" target="_blank" rel="noopener">
                <x-bs-admin::icon name="ri-external-link" class="{{ $sm }}" />{{ __('admin.sl.open_console', ['console' => $p('console')]) }}
            </x-bs-admin::button>
            <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="guide = ! guide" x-bind:aria-expanded="guide ? 'true' : 'false'" aria-controls="sl-guide-{{ $key }}">
                <span x-show="! guide" class="inline-flex items-center gap-1.5"><x-bs-admin::icon name="ri-book-open" class="{{ $sm }}" />{{ __('admin.sl.guide_open') }}</span>
                <span x-show="guide" class="inline-flex items-center gap-1.5"><x-bs-admin::icon name="ri-arrow-up-s" class="{{ $sm }}" />{{ __('admin.sl.guide_close') }}</span>
            </x-bs-admin::button>
        </div>

        <div id="sl-guide-{{ $key }}" x-show="guide" class="mb-3.5 rounded-md border border-border bg-surface">
            <h4 class="{{ $blkHead }} rounded-t-md border-b border-border-soft">{{ __('admin.sl.guide_do', ['console' => mb_strtoupper($p('console'))]) }}</h4>
            <ol class="m-0 flex list-none flex-col gap-3 px-3.5 py-3">
                @foreach ([1, 2, 3, 4, 5] as $n)
                    <li class="flex items-start gap-2.5">
                        <span class="mt-px inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-soft text-caption font-bold text-accent" aria-hidden="true">{{ $n }}</span>
                        <span class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted"><b class="font-semibold text-text-strong">{{ $p('step'.$n.'_t') }}</b> — {{ $p('step'.$n.'_d') }}</span>
                    </li>
                @endforeach
            </ol>
            <div class="{{ $blkHead }} flex flex-wrap items-center justify-between gap-2.5 border-y border-border-soft">
                <h4>{{ __('admin.sl.guide_copy') }}</h4>
                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="copyAll()">
                    <span x-show="copiedKey !== 'all'" class="inline-flex"><x-bs-admin::icon name="ri-file-copy" class="{{ $sm }}" /></span><span x-show="copiedKey === 'all'" class="inline-flex"><x-bs-admin::icon name="ri-check" class="{{ $sm }}" /></span>{{ __('admin.sl.copy_all') }}
                </x-bs-admin::button>
            </div>
            <div class="px-3.5 pt-1.5 pb-3">
                @foreach ($rows as $row)
                    <div class="flex flex-col gap-1.5 border-t border-border-soft py-2.5 first:border-t-0">
                        <span class="{{ $label }}">
                            {{ $row['label'] }}
                            <x-bs-admin::help-pop :label="__('admin.sl.where_aria')"><b>{{ __('admin.sl.paste_into') }}</b> {{ $row['where'] }}<br>{{ $row['why'] }}</x-bs-admin::help-pop>
                        </span>
                        <span class="flex items-stretch overflow-hidden rounded-md border border-border bg-surface-muted">
                            <span class="min-w-0 flex-1 py-2 ps-3 pe-1 font-mono text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-accent [overflow-wrap:anywhere]">{{ $row['value'] }}</span>
                            <button type="button" x-on:click="copy(@js($row['key']), @js($row['value']))"
                                    aria-label="{{ __('admin.sl.copy_aria', ['label' => $row['label']]) }}" title="{{ __('admin.sl.copy_aria', ['label' => $row['label']]) }}"
                                    class="hit-area inline-flex w-[calc(2.125rem+var(--user-text-boost))] shrink-0 items-center justify-center border-s border-border text-text-muted transition-colors hover:bg-primary-soft hover:text-accent"
                                    x-bind:class="copiedKey === @js($row['key']) && 'text-success-text'">
                                <span x-show="copiedKey !== @js($row['key'])" class="inline-flex"><x-bs-admin::icon name="ri-file-copy" class="{{ $sm }}" /></span>
                                <span x-show="copiedKey === @js($row['key'])" class="inline-flex"><x-bs-admin::icon name="ri-check" class="{{ $sm }}" /></span>
                            </button>
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="{{ $blkHead }} flex items-center gap-1 border-y border-border-soft">
                <h4><label for="sl-paste-{{ $key }}">{{ __('admin.sl.guide_paste') }}</label></h4>
                <x-bs-admin::help-pop :label="__('admin.sl.paste_aria')">{{ $p('paste_help') }}</x-bs-admin::help-pop>
            </div>
            <div class="px-3.5 py-3">
                <textarea id="sl-paste-{{ $key }}" rows="2" x-bind:value="paste" x-on:input="pasted($event.target.value)" placeholder="{{ $p('paste_ph') }}" spellcheck="false" autocomplete="off"
                          class="w-full resize-y rounded border border-dashed border-border-input bg-surface px-3 py-2 text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text placeholder:text-text-muted focus:border-solid @max-[37.4999rem]/sl:text-[calc(1rem+var(--user-text-boost))]"></textarea>
                <x-bs-admin::sr-live x-text="pasteMsg ? pasteMsg.text : ''" />
                <p x-show="pasteMsg" class="mt-2 flex items-start gap-1.5 text-caption leading-normal" x-bind:class="pasteMsg && pasteMsg.ok ? 'text-success-text' : 'text-warning-text'">
                    <span x-show="pasteMsg && pasteMsg.ok" class="inline-flex"><x-bs-admin::icon name="ri-checkbox-circle" class="{{ $sm }}" /></span>
                    <span x-show="pasteMsg && ! pasteMsg.ok" class="inline-flex"><x-bs-admin::icon name="ri-information" class="{{ $sm }}" /></span>
                    <span x-text="pasteMsg ? pasteMsg.text : ''"></span>
                </p>
            </div>
        </div>

        <div class="mb-2 flex flex-col gap-0.5">
            <h4 class="text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.sl.keys_title') }}</h4>
            <p class="text-caption text-text-muted">{{ __('admin.sl.keys_desc', ['console' => $p('console')]) }}</p>
        </div>
        <div class="grid gap-x-3.5 gap-y-3 @min-[48rem]/sl:grid-cols-2">
            <div class="flex flex-col gap-1.5">
                <span class="{{ $label }}"><label for="sl-id-{{ $key }}">{{ $idLabel }}</label><x-bs-admin::help-pop :label="__('admin.sl.id_where_aria')">{{ $p('id_help') }}</x-bs-admin::help-pop></span>
                <input id="sl-id-{{ $key }}" x-ref="id" type="text" class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text" x-bind:value="id" x-on:input="typedId($event.target.value)"
                       x-bind:aria-invalid="idError ? 'true' : 'false'" aria-describedby="sl-id-err-{{ $key }}"
                       placeholder="{{ $p('id_ph') }}" autocomplete="off" spellcheck="false" value="{{ $app['client_id'] }}">
                <x-bs-admin::field-error id="sl-id-err-{{ $key }}" x-show="idError"><span x-text="idError"></span></x-bs-admin::field-error>
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="{{ $label }}"><label for="sl-sec-{{ $key }}" x-show="editingSecret">{{ $secretLabel }}</label><span x-show="! editingSecret">{{ $secretLabel }}</span><x-bs-admin::help-pop align="end" :label="__('admin.sl.secret_where_aria')">{{ $p('secret_help') }}</x-bs-admin::help-pop></span>
                <span x-show="! editingSecret" class="flex min-h-control flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-caption font-semibold text-success-text"><x-bs-admin::icon name="ri-lock" class="{{ $sm }}" />{{ __('admin.sl.secret_saved') }}</span>
                    <span class="min-w-0 flex-[1_1_8.75rem] text-caption text-text-muted">{{ __('admin.sl.secret_hidden') }}</span>
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="replace()" :aria-label="__('admin.sl.replace_aria', ['label' => $secretLabel])">{{ __('admin.sl.replace') }}</x-bs-admin::button>
                </span>
                <span x-show="editingSecret" class="flex flex-col gap-1.5">
                    <input id="sl-sec-{{ $key }}" x-ref="secret" type="password" class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text" x-bind:value="secret" x-on:input="typedSecret($event.target.value)"
                           x-bind:aria-invalid="secretError ? 'true' : 'false'" aria-describedby="sl-sec-err-{{ $key }}"
                           placeholder="{{ $p('secret_ph') }}" autocomplete="new-password" spellcheck="false">
                    <span x-show="saved" class="text-caption text-text-muted">{{ __('admin.sl.keep_blank') }}</span>
                </span>
                <x-bs-admin::field-error id="sl-sec-err-{{ $key }}" x-show="secretError"><span x-text="secretError"></span></x-bs-admin::field-error>
            </div>
        </div>

        <div class="mt-3.5 flex flex-wrap items-center gap-2.5">
            <x-bs-admin::button x-bind:disabled="testDisabled" x-on:click="test()">
                <span x-show="! testing" class="inline-flex"><x-bs-admin::icon name="ri-shield-check" class="{{ $sm }}" /></span><span x-show="testing" class="inline-flex"><x-bs-admin::icon name="ri-loader-4" class="{{ $sm }} animate-spin motion-reduce:animate-none" /></span>
                <span x-text="testing ? @js(__('admin.sl.testing')) : @js(__('admin.sl.test'))">{{ __('admin.sl.test') }}</span>
            </x-bs-admin::button>
            @if ($app['connected'])
                <x-bs-admin::button variant="outline" color="danger" wire:click="askDisconnect('{{ $key }}')">
                    <x-bs-admin::icon name="ri-link-unlink-m" class="{{ $sm }}" />{{ __('admin.sl.disconnect') }}
                </x-bs-admin::button>
            @endif
            <span class="text-caption leading-normal text-text-muted" x-text="testing ? @js(__('admin.sl.test_hint_busy')) : @js(__('admin.sl.test_hint'))">{{ __('admin.sl.test_hint') }}</span>
        </div>

        <div class="mt-3 border-t border-border pt-3">
            <button type="button" x-on:click="trouble = ! trouble" x-bind:aria-expanded="trouble ? 'true' : 'false'" aria-controls="sl-trouble-{{ $key }}"
                    class="hit-area inline-flex items-center gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-accent">
                <span x-show="! trouble" class="inline-flex"><x-bs-admin::icon name="ri-lifebuoy" class="{{ $sm }}" /></span><span x-show="trouble" class="inline-flex"><x-bs-admin::icon name="ri-arrow-up-s" class="{{ $sm }}" /></span>{{ __('admin.sl.trouble') }}
            </button>
            <div id="sl-trouble-{{ $key }}" x-show="trouble" class="mt-2.5 flex flex-col gap-2.5">
                @foreach ([1, 2, 3] as $n)
                    <div class="rounded-md border border-border-strong bg-surface px-3.5 py-3">
                        <p class="text-[calc(0.78125rem+var(--user-text-boost))] leading-normal font-semibold text-text-strong">{{ $p('q'.$n) }}</p>
                        <p class="mt-1 text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted">{{ $p('a'.$n) }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
