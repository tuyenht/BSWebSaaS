{{--
    "Khi bấm nút" (the page builder design): what one block button does. $button = one view of
    ButtonActions::views(). The kind sits in one button that opens the list of kinds; under it the
    value of that kind. Every call names the button by its path; each typed field is saved and
    checked when it is left (wire:blur), and its problem is read with the field (aria-describedby).
--}}
@php
    $b = $button;
    $id = $b['id'];
    $p = $b['path'];
    $v = $b['value'];
    $err = $b['error'];
    $errId = $id.'-e';
    $hintId = $id.'-h';
    $desc = $hintId.($err !== null ? ' '.$errId : '');
    $kindLabel = __('entries.btn_kind_'.$b['kind']);
    $title = $b['label'] !== '' ? __('entries.btn_when_named', ['label' => $b['label']]) : __('entries.btn_when');
    $group = $b['label'] !== '' ? __('entries.btn_group_named', ['label' => $b['label']]) : __('entries.btn_group');
    $fieldEdge = $err !== null ? ' border-danger-text btn-act__in--bad' : ' border-border-input';
@endphp
<div class="btn-act" id="{{ $id }}" wire:key="btn-act-{{ $b['fid'] }}" role="group" aria-labelledby="{{ $id }}-t">
    <span class="btn-act__title" id="{{ $id }}-t">{{ $title }}</span>
    <button type="button" class="btn-act__cur" id="{{ $id }}-cur" wire:click="toggleButtonKinds('{{ $p }}')" aria-expanded="{{ $b['open'] ? 'true' : 'false' }}">
        <x-bs-admin::icon :name="$b['icon']" class="btn-act__glyph" />
        <span class="btn-act__ct"><span class="sr-only">{{ __('entries.btn_doing') }} </span>{{ $kindLabel }}</span>
        <span class="btn-act__chg">{{ $b['open'] ? __('entries.btn_close') : __('entries.btn_change') }}<x-bs-admin::icon :name="$b['open'] ? 'ri-arrow-up-s' : 'ri-arrow-down-s'" class="btn-act__chg-ic" /></span>
    </button>

    @if ($b['open'])
        <div class="btn-act__list" role="radiogroup" aria-label="{{ $group }}">
            @foreach (\App\Modules\Theme\Components\ButtonLink::KINDS as $kind)
                <button type="button" class="btn-act__mi" role="radio" aria-checked="{{ $kind === $b['kind'] ? 'true' : 'false' }}" wire:click="setButtonKind('{{ $p }}', '{{ $kind }}')">
                    <x-bs-admin::icon :name="\App\Modules\Admin\Support\ButtonActions::ICONS[$kind]" class="btn-act__glyph" />
                    <span class="btn-act__kt"><span>{{ __('entries.btn_kind_'.$kind) }}</span><span class="btn-act__meta">{{ __('entries.btn_kind_'.$kind.'_hint') }}</span></span>
                    @if ($kind === $b['kind'])
                        <x-bs-admin::icon name="ri-check" class="btn-act__ck" />
                    @endif
                </button>
            @endforeach
        </div>
    @else
        @if ($b['auto'])
            <p class="btn-act__hint btn-act__mig"><x-bs-admin::icon name="ri-information" class="btn-act__mig-ic" /><span>{{ __('entries.btn_migrated', ['raw' => $b['raw'], 'kind' => $kindLabel]) }}</span></p>
        @endif

        @switch($b['kind'])
            @case('page')
                @if (! $b['searching'])
                    <div class="btn-act__card" id="{{ $id }}-pg" tabindex="-1">
                        <span class="btn-act__ic"><x-bs-admin::icon :name="$b['page']['icon'] ?? 'ri-file-text'" class="btn-act__ic-glyph" /></span>
                        <span class="btn-act__kt"><span class="btn-act__name">{{ $b['page']['title'] ?? __('entries.btn_page_gone_name') }}</span><span class="btn-act__meta">{{ $b['page']['meta'] ?? $b['pageShown'] }}</span></span>
                    </div>
                    @if ($err !== null)
                        <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                    @endif
                    <div class="btn-act__row">
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="searchButtonPage('{{ $p }}')"><x-bs-admin::icon name="ri-search" class="btn-act__btn-ic" />{{ __('entries.btn_page_other') }}</x-bs-admin::button>
                    </div>
                @else
                    <label class="btn-act__lbl" for="{{ $id }}-q">{{ __('entries.btn_page_search') }}</label>
                    <input id="{{ $id }}-q" type="search" class="field block w-full rounded border bg-surface px-3 text-text placeholder:text-placeholder{{ $fieldEdge }}" wire:model.live.debounce.300ms="buttonQueries.{{ $b['fid'] }}" wire:blur="touchButton('{{ $p }}')"
                           placeholder="{{ __('entries.btn_page_search_ph') }}" autocomplete="off" aria-describedby="{{ $desc }}" aria-invalid="{{ $err !== null ? 'true' : 'false' }}">
                    @if ($err !== null)
                        <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                    @endif
                    @if ($b['results'] !== [])
                        <div class="btn-act__list" role="radiogroup" aria-label="{{ __('entries.btn_page_results') }}">
                            @foreach ($b['results'] as $result)
                                <button type="button" class="btn-act__mi" role="radio" aria-checked="{{ ($v['page'] ?? null) === $result['ulid'] ? 'true' : 'false' }}" wire:click="pickButtonPage('{{ $p }}', '{{ $result['ulid'] }}')" wire:key="btn-act-{{ $b['fid'] }}-r-{{ $result['ulid'] }}">
                                    <x-bs-admin::icon :name="$result['icon']" class="btn-act__glyph" />
                                    <span class="btn-act__kt"><span>{{ $result['title'] }}</span><span class="btn-act__meta">{{ $result['meta'] }}</span></span>
                                    @if (($v['page'] ?? null) === $result['ulid'])
                                        <x-bs-admin::icon name="ri-check" class="btn-act__ck" />
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @else
                        <div class="btn-act__empty"><x-bs-admin::icon name="ri-search" class="btn-act__empty-ic" /><span>{{ $b['query'] !== '' ? __('entries.btn_page_none_found', ['q' => $b['query']]) : __('entries.btn_page_none_yet') }}</span></div>
                    @endif
                    @if (($v['page'] ?? '') !== '' || isset($v['path']))
                        <div class="btn-act__row">
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="keepButtonPage('{{ $p }}')"><x-bs-admin::icon name="ri-arrow-go-back" class="btn-act__btn-ic" />{{ __('entries.btn_page_keep') }}</x-bs-admin::button>
                        </div>
                    @endif
                    <p class="btn-act__hint" id="{{ $hintId }}">{{ __('entries.btn_page_hint') }}</p>
                @endif
                @break

            @case('url')
                <label class="btn-act__lbl" for="{{ $id }}-in">{{ __('entries.btn_url_label') }}</label>
                <input id="{{ $id }}-in" type="url" inputmode="url" autocomplete="url" class="field block w-full rounded border bg-surface px-3 text-text placeholder:text-placeholder{{ $fieldEdge }}" value="{{ $v['url'] ?? '' }}"
                       wire:blur="setButtonField('{{ $p }}', 'url', $event.target.value)" placeholder="https://" aria-invalid="{{ $err !== null ? 'true' : 'false' }}" aria-describedby="{{ $desc }}">
                @if ($err !== null)
                    <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                @endif
                <div class="btn-act__tgt"><button type="button" class="switch-row" role="switch" aria-checked="{{ ($v['blank'] ?? true) !== false ? 'true' : 'false' }}" wire:click="toggleButtonBlank('{{ $p }}')">{{ __('entries.btn_url_new_tab') }}</button></div>
                <p class="btn-act__hint" id="{{ $hintId }}">{{ __('entries.btn_url_hint') }}</p>
                @break

            @case('anchor')
                @if ($b['anchors'] !== [])
                    <div class="btn-act__list" role="radiogroup" aria-label="{{ __('entries.btn_anchor_group') }}">
                        @foreach ($b['anchors'] as $anchor)
                            <button type="button" class="btn-act__mi" role="radio" aria-checked="{{ ($v['anchor'] ?? null) === $anchor['id'] ? 'true' : 'false' }}" wire:click="pickButtonAnchor('{{ $p }}', '{{ $anchor['id'] }}')" wire:key="btn-act-{{ $b['fid'] }}-a-{{ $anchor['id'] }}">
                                <x-bs-admin::icon :name="$anchor['icon']" class="btn-act__glyph" />
                                <span class="btn-act__kt"><span>{{ $anchor['label'] }}</span><span class="btn-act__meta">{{ $anchor['meta'] }}</span></span>
                                @if (($v['anchor'] ?? null) === $anchor['id'])
                                    <x-bs-admin::icon name="ri-check" class="btn-act__ck" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                    @if ($err !== null)
                        <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                    @endif
                    <p class="btn-act__hint" id="{{ $hintId }}">{{ __('entries.btn_anchor_hint') }}</p>
                @else
                    <div class="btn-act__empty" id="{{ $hintId }}"><x-bs-admin::icon name="ri-hashtag" class="btn-act__empty-ic" /><span><b>{{ __('entries.btn_anchor_none_title') }}</b> {{ __('entries.btn_anchor_none_how') }}</span></div>
                    @if ($err !== null)
                        <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                    @endif
                @endif
                @break

            @case('tel')
            @case('zalo')
                @php $zalo = $b['kind'] === 'zalo'; @endphp
                <label class="btn-act__lbl" for="{{ $id }}-in">{{ $zalo ? __('entries.btn_zalo_label') : __('entries.btn_tel_label') }}</label>
                <input id="{{ $id }}-in" type="{{ $zalo ? 'text' : 'tel' }}" inputmode="{{ $zalo ? 'text' : 'tel' }}" autocomplete="tel" class="field block w-full rounded border bg-surface px-3 text-text placeholder:text-placeholder{{ $fieldEdge }}" value="{{ $v['phone'] ?? '' }}"
                       wire:blur="setButtonField('{{ $p }}', 'phone', $event.target.value)" placeholder="{{ __('entries.btn_phone_ph') }}" aria-invalid="{{ $err !== null ? 'true' : 'false' }}" aria-describedby="{{ $desc }}">
                @if ($err !== null)
                    <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                @endif
                @if ($b['sitePhone'] !== '' && ! $b['siteSame'])
                    <div class="btn-act__row">
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="useSitePhone('{{ $p }}')"><x-bs-admin::icon name="ri-building" class="btn-act__btn-ic" />{{ __('entries.btn_site_phone', ['phone' => $b['sitePhone']]) }}</x-bs-admin::button>
                    </div>
                @elseif ($b['siteSame'])
                    <p class="btn-act__hint btn-act__mig"><x-bs-admin::icon name="ri-check" class="btn-act__mig-ic" /><span>{{ __('entries.btn_site_phone_same') }}</span></p>
                @endif
                <p class="btn-act__hint" id="{{ $hintId }}">{{ $zalo ? __('entries.btn_zalo_hint') : __('entries.btn_tel_hint') }}</p>
                @break

            @case('mail')
                <label class="btn-act__lbl" for="{{ $id }}-in">{{ __('entries.btn_mail_label') }}</label>
                <input id="{{ $id }}-in" type="email" inputmode="email" autocomplete="email" class="field block w-full rounded border bg-surface px-3 text-text placeholder:text-placeholder{{ $fieldEdge }}" value="{{ $v['email'] ?? '' }}"
                       wire:blur="setButtonField('{{ $p }}', 'email', $event.target.value)" placeholder="{{ __('entries.btn_mail_ph') }}" aria-invalid="{{ $err !== null ? 'true' : 'false' }}" aria-describedby="{{ $desc }}">
                @if ($err !== null)
                    <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                @endif
                <label class="btn-act__lbl" for="{{ $id }}-sub">{{ __('entries.btn_mail_subject') }}</label>
                <input id="{{ $id }}-sub" type="text" class="field block w-full rounded border bg-surface px-3 text-text placeholder:text-placeholder border-border-input" value="{{ $v['subject'] ?? '' }}" maxlength="{{ \App\Modules\Theme\Components\ButtonLink::SUBJECT_MAX }}"
                       wire:blur="setButtonField('{{ $p }}', 'subject', $event.target.value)" placeholder="{{ __('entries.btn_mail_subject_ph') }}">
                <p class="btn-act__hint" id="{{ $hintId }}">{{ __('entries.btn_mail_hint') }}</p>
                @break

            @case('file')
                @if (($v['file'] ?? '') !== '')
                    <div class="btn-act__card" id="{{ $id }}-pg" tabindex="-1">
                        <span class="btn-act__ic"><x-bs-admin::icon :name="$b['file']['icon'] ?? 'ri-file'" class="btn-act__ic-glyph" /></span>
                        <span class="btn-act__kt"><span class="btn-act__name">{{ $b['file']['name'] ?? __('entries.btn_file_gone_name') }}</span>@if ($b['file'] !== null)<span class="btn-act__meta">{{ $b['file']['meta'] }}</span>@endif</span>
                    </div>
                    @if ($err !== null)
                        <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                    @endif
                    @can('media.view')
                        <div class="btn-act__row">
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" id="{{ $id }}-file" wire:click="openButtonFilePicker('{{ $p }}')"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_change') }}</x-bs-admin::button>
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" icon :aria-label="__('entries.btn_file_clear')" :title="__('entries.btn_file_clear')" wire:click="clearButtonFile('{{ $p }}')"><x-bs-admin::icon name="ri-delete-bin" class="btn-act__btn-ic" /></x-bs-admin::button>
                        </div>
                    @endcan
                @else
                    <div class="btn-act__empty"><x-bs-admin::icon name="ri-file" class="btn-act__empty-ic" /><span>{{ __('entries.btn_file_none') }}</span></div>
                    @if ($err !== null)
                        <p class="btn-act__err" id="{{ $errId }}" role="alert"><x-bs-admin::icon name="ri-error-warning" class="btn-act__err-ic" /><span>{{ $err }}</span></p>
                    @endif
                    @can('media.view')
                        <div class="btn-act__row">
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" id="{{ $id }}-file" wire:click="openButtonFilePicker('{{ $p }}')"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_pick') }}</x-bs-admin::button>
                        </div>
                    @endcan
                @endif
                @break
        @endswitch
    @endif
</div>
