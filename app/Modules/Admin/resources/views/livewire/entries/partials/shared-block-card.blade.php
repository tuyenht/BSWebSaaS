{{-- A shared block placed in «Trình dựng» (board 27 .pb3-ref): a dashed card with a lock — what it shows
     is the block's own content, edited in the block library («Mở khối», a new tab, for someone who may
     edit blocks). A draft says visitors do not see it yet; a block deleted from the library says so and
     offers «Bỏ khỏi trang»; a block without this language says what visitors see instead. Its panel
     (board «Khối đang đặt ở đây») opens under the card: order, status, code, languages, places, and why
     there is no «Trình bày» here. $i, $last, $block, $refs, $openBlock, $rowButton, $askDelete,
     $localeLabel and $unit come from block-rows. --}}
@php
    $refUlid = \App\Modules\Theme\Components\BlockReference::ulid($block);
    $ref = $refUlid !== null ? (($refs ?? [])[$refUlid] ?? null) : null;
    $refState = $ref === null ? 'gone' : ($ref['draft'] ? 'draft' : 'ok');
    $refName = $ref['name'] ?? __('entries.ref.gone_name');
    $refOpen = $ref['href'] ?? null;
    $refText = match (true) {
        $ref === null => __('entries.ref.text_gone'),
        $refOpen === null => __('entries.ref.text_nr'),
        default => __('entries.ref.text_ok'),
    };
    $refWarn = 'ref-card__w';
    $refGlyph = 'size-[calc(1rem+var(--user-text-boost)*0.5)] shrink-0';
@endphp
<li wire:key="block-{{ $i }}" class="ref-card min-w-0" data-k="{{ $refState }}" data-ref-card="{{ $refState }}">
    <div class="ref-card__hd">
        <span class="ref-card__ic" aria-hidden="true">
            <x-bs-admin::icon :name="$ref === null ? 'ri-error-warning' : 'ri-stack'" class="size-[calc(1.25rem+var(--user-text-boost)*0.5)]" />
        </span>
        <span class="ref-card__tt">
            <span class="ref-card__chip"><x-bs-admin::icon name="ri-lock" :class="$refGlyph" />{{ __('entries.block.reusable_ref') }}</span>
            <span class="ref-card__nm">{{ $refName }}</span>
        </span>
        <button type="button" id="block-ref-{{ $i }}" class="{{ $rowButton }}" title="{{ __('entries.ref.details') }}" aria-label="{{ __('entries.ref.details') }}: {{ $refName }}"
                aria-controls="block-form-{{ $i }}" aria-expanded="{{ $openBlock === $i ? 'true' : 'false' }}"
                x-on:click="openBlock = openBlock === {{ $i }} ? null : {{ $i }}" x-bind:aria-expanded="openBlock === {{ $i }} ? 'true' : 'false'">
            <x-bs-admin::icon name="ri-information" class="size-4" />
        </button>
        <button type="button" class="{{ $rowButton }} hover:border-danger-text hover:text-danger-text"
                x-on:click="{{ $askDelete(__('entries.ed.del_block_text', ['lang' => $localeLabel, 'unit' => mb_strtolower($unit)])) }}.then(ok => ok && $wire.removeBlock({{ $i }}))"
                title="{{ __('entries.ed.block_remove') }}" aria-label="{{ __('entries.ed.block_remove') }}: {{ $refName }}">
            <x-bs-admin::icon name="ri-delete-bin" class="size-4" />
        </button>
    </div>
    <p class="ref-card__tx">{{ $refText }}</p>
    @if ($refState === 'draft')
        <p class="{{ $refWarn }}" data-k="draft"><x-bs-admin::icon name="ri-draft" :class="$refGlyph" />{{ $refOpen !== null ? __('entries.ref.warn_draft') : __('entries.ref.warn_draft_nr') }}</p>
    @elseif ($refState === 'gone')
        <p class="{{ $refWarn }}" data-k="gone"><x-bs-admin::icon name="ri-delete-bin" :class="$refGlyph" />{{ __('entries.ref.warn_gone') }}</p>
    @endif
    @if (($ref['loc'] ?? null) !== null)
        <p class="{{ $refWarn }}" data-k="loc"><x-bs-admin::icon name="ri-translate-2" :class="$refGlyph" />{{ $ref['loc'] }}</p>
    @endif
    @if ($ref === null)
        <x-bs-admin::button variant="outline" color="danger" size="sm" class="self-start" wire:click="removeBlock({{ $i }})" data-ref-remove aria-label="{{ __('entries.ref.remove_named', ['name' => $refName]) }}">
            <x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ __('entries.ref.remove') }}
        </x-bs-admin::button>
    @elseif ($refOpen !== null)
        <a class="ref-card__open" href="{{ $refOpen }}" target="_blank" rel="noopener" data-ref-open-block aria-label="{{ __('entries.ref.open_named', ['name' => $refName]) }}">
            <x-bs-admin::icon name="ri-external-link" :class="$refGlyph" />{{ __('entries.ref.open') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span>
        </a>
    @endif
    <div id="block-form-{{ $i }}" class="ref-card__pn" x-show="openBlock === {{ $i }}" @if ($openBlock !== $i) style="display: none" @endif>
        <p class="m-0 text-small font-semibold text-text-strong">{{ __('entries.ref.panel_title') }}</p>
        <div class="flex flex-wrap gap-2">
            <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="moveUp({{ $i }})" :disabled="$i === 0">
                <x-bs-admin::icon name="ri-arrow-up" class="size-4" /> {{ __('entries.ed.block_up') }}
            </x-bs-admin::button>
            <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="moveDown({{ $i }})" :disabled="$i === $last">
                <x-bs-admin::icon name="ri-arrow-down" class="size-4" /> {{ __('entries.ed.block_down') }}
            </x-bs-admin::button>
        </div>
        <dl class="ref-card__dl">
            <div>
                <dt>{{ __('entries.ref.f_status') }}</dt>
                <dd>
                    @if ($refState === 'ok')
                        <x-bs-admin::icon name="ri-checkbox-circle" :class="$refGlyph.' text-success-text'" />{{ __('entries.ref.st_published') }}
                    @elseif ($refState === 'draft')
                        <x-bs-admin::icon name="ri-draft" :class="$refGlyph.' text-warning-text'" />{{ __('entries.ref.st_draft') }}
                    @else
                        <x-bs-admin::icon name="ri-error-warning" :class="$refGlyph.' text-danger-text'" />{{ __('entries.ref.st_gone') }}
                    @endif
                </dd>
            </div>
            @if ($ref !== null)
                <div><dt>{{ __('entries.ref.f_key') }}</dt><dd><code>{{ $ref['key'] }}</code></dd></div>
                @if ($ref['langs'] !== '')
                    <div><dt>{{ __('entries.ref.f_langs') }}</dt><dd>{{ $ref['langs'] }}</dd></div>
                @endif
                <div><dt>{{ __('entries.ref.f_uses') }}</dt><dd>{{ $ref['uses'] }}</dd></div>
            @endif
        </dl>
        @if ($refOpen !== null)
            <p class="m-0 text-caption text-text-muted">{{ __('entries.ref.open_hint') }}</p>
        @endif
        <div class="flex flex-col gap-1 border-t border-border pt-3">
            <p class="m-0 text-small font-semibold text-text-strong">{{ __('entries.look_heading') }}</p>
            <p class="m-0 text-caption text-text-muted">{{ __('entries.ref.look_note') }}</p>
        </div>
    </div>
</li>
