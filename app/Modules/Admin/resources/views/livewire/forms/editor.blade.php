@php
    use Illuminate\Support\Js;

    $in = 'block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text';
    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card';
    $head = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $h2 = 'm-0 min-w-0 flex-auto text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong';
    $lab = 'mb-1.5 flex flex-wrap items-center gap-1.5 text-small font-semibold text-text-strong';
    $hint = 'm-0 mt-1.5 text-small text-text-muted [overflow-wrap:anywhere]';
    $err = fn (string $key): ?string => $shown[$key]['msg'] ?? null;
    $last = count($fields) - 1;
@endphp
<div class="flex flex-col gap-4" x-on:bm-focus.window="requestAnimationFrame(() => document.getElementById($event.detail.id)?.focus())"
     x-on:bm-first-error.window="$wire.openFirstError()">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        {{-- the way back sits on its own line on a phone, beside the title from 640 --}}
        <div class="basis-full sm:basis-auto">
            <x-bs-admin::button variant="ghost" color="secondary" :href="route('admin.form-builder.index')" wire:navigate>
                <x-bs-admin::icon name="ri-arrow-left" class="size-4" /> {{ __('form_builder.title') }}
            </x-bs-admin::button>
        </div>
        <h1 class="m-0 min-w-0 flex-auto text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h1>
    </div>

    <div class="flex flex-wrap items-start gap-4">
        <div class="flex min-w-0 flex-[999_1_520px] flex-col gap-4">
            {{-- Thông tin chung --}}
            <section class="{{ $card }}" aria-labelledby="bm-s1">
                <div class="{{ $head }}"><h2 id="bm-s1" class="{{ $h2 }}">{{ __('form_builder.card_general') }}</h2></div>
                <div class="flex min-w-0 flex-col gap-4 p-4">
                    <div>
                        <label for="bm-name" class="{{ $lab }}">{{ __('form_builder.name_label') }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                        <input id="bm-name" type="text" class="field {{ $in }}" wire:model.blur="name" maxlength="200" required
                            placeholder="{{ __('form_builder.name_placeholder') }}" @if ($err('name')) aria-invalid="true" @endif
                            aria-describedby="@if ($err('name'))bm-name-e @endif bm-name-h" @if ($isNew && $ulid === null) autofocus @endif>
                        @if ($err('name'))
                            <x-bs-admin::field-error id="bm-name-e" class="mt-1.5">{{ $err('name') }}</x-bs-admin::field-error>
                        @endif
                        <p id="bm-name-h" class="{{ $hint }}">{{ __('form_builder.name_hint') }}</p>
                    </div>
                    <div class="flex min-h-hit items-center gap-3">
                        <div class="min-w-0 flex-auto">
                            <b id="bm-active-l" class="block font-semibold text-text-strong">{{ __('form_builder.active_label') }} · {{ $active ? __('form_builder.on') : __('form_builder.off') }}</b>
                            <span id="bm-active-h" class="text-small text-text-muted">{{ $active ? __('form_builder.active_on_hint') : __('form_builder.active_off_hint') }}</span>
                        </div>
                        <button type="button" role="switch" class="switch hit-area" aria-checked="{{ $active ? 'true' : 'false' }}"
                            aria-label="{{ __('form_builder.active_label') }}" aria-describedby="bm-active-h" wire:click="$toggle('active')"></button>
                    </div>
                </div>
            </section>

            {{-- Các ô khách cần điền --}}
            <section class="{{ $card }}" aria-labelledby="bm-s2">
                <div class="{{ $head }}">
                    <h2 id="bm-s2" class="{{ $h2 }}">{{ __('form_builder.card_fields') }}</h2>
                    <span class="text-small text-text-muted">{{ trans_choice('form_builder.fields_n', count($fields) + 1, ['n' => count($fields) + 1]) }}</span>
                </div>
                <div class="flex min-w-0 flex-col gap-4 p-4">
                    <p class="m-0 text-small text-text-muted">{{ __('form_builder.fields_hint_drag') }} <x-bs-admin::icon name="ri-draggable" class="inline size-4 align-text-bottom" /> {{ __('form_builder.fields_hint_rest') }}</p>
                    <div class="flex flex-col overflow-hidden rounded-md border border-border" role="list" aria-label="{{ __('form_builder.fields_label') }}" wire:sort="sortField">
                        @foreach ($fields as $i => $f)
                            @php
                                $key = $f['key'];
                                $open = $openKey === $key;
                                $label = trim($f['label']) !== '' ? $f['label'] : __('form_builder.field_unnamed');
                                $fe = $err('f:'.$key);
                                $labelBad = $fe !== null && ($shown['f:'.$key]['id'] ?? '') === 'bm-l-'.$key;
                                $choicesBad = $fe !== null && ! $labelBad;
                            @endphp
                            <div role="listitem" wire:key="bm-f-{{ $key }}" wire:sort:item="{{ $key }}" @class(['flex flex-col bg-surface', 'border-t border-border' => $i > 0])>
                                <div class="flex min-h-13 flex-wrap items-center gap-2 py-1.5 pe-2 ps-1">
                                    <span wire:sort:handle class="inline-flex h-11 w-8 shrink-0 cursor-grab items-center justify-center text-text-muted" title="{{ __('form_builder.drag') }}" aria-hidden="true">
                                        <x-bs-admin::icon name="ri-draggable" class="size-5" />
                                    </span>
                                    <div class="flex min-w-0 flex-[1_1_200px] flex-col">
                                        <b class="font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $label }}</b>
                                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-text-muted">
                                            <span class="inline-flex items-center gap-1"><x-bs-admin::icon :name="$kinds[$f['type']] ?? 'ri-text'" class="size-4" /> {{ __('form_builder.type.'.$f['type']) }}</span>
                                            @if ($f['required'])<span class="font-semibold text-danger-text">* {{ __('form_builder.required') }}</span>@endif
                                            @if ($fe !== null && ! $open)<span class="inline-flex items-center gap-1 text-danger-text"><x-bs-admin::icon name="ri-error-warning" class="size-4" />{{ $fe }}</span>@endif
                                        </span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1" wire:sort:ignore>
                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="move({{ Js::from($key) }}, -1)"
                                            :aria-disabled="$i === 0 ? 'true' : 'false'" class="aria-disabled:opacity-50"
                                            :aria-label="__('form_builder.up_aria', ['label' => $label])" :title="__('form_builder.up_aria', ['label' => $label])">
                                            <x-bs-admin::icon name="ri-arrow-up" class="size-4" /><span class="max-[599.98px]:sr-only">{{ __('form_builder.up') }}</span>
                                        </x-bs-admin::button>
                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="move({{ Js::from($key) }}, 1)"
                                            :aria-disabled="$i === $last ? 'true' : 'false'" class="aria-disabled:opacity-50"
                                            :aria-label="__('form_builder.down_aria', ['label' => $label])" :title="__('form_builder.down_aria', ['label' => $label])">
                                            <x-bs-admin::icon name="ri-arrow-down" class="size-4" /><span class="max-[599.98px]:sr-only">{{ __('form_builder.down') }}</span>
                                        </x-bs-admin::button>
                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="toggleField({{ Js::from($key) }})"
                                            aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="bm-fe-{{ $key }}">
                                            <x-bs-admin::icon :name="$open ? 'ri-arrow-up-s' : 'ri-pencil'" class="size-4" /> {{ $open ? __('form_builder.collapse') : __('form_builder.edit') }}
                                        </x-bs-admin::button>
                                    </div>
                                </div>
                                @if ($open)
                                    <div id="bm-fe-{{ $key }}" class="flex flex-col gap-3 border-t border-dashed border-border-strong bg-surface-muted px-4 pb-4 pt-3" wire:sort:ignore>
                                        <div class="grid gap-4 min-[600px]:grid-cols-2">
                                            <div>
                                                <label for="bm-l-{{ $key }}" class="{{ $lab }}">{{ __('form_builder.field_label') }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                                                <input id="bm-l-{{ $key }}" type="text" class="field {{ $in }}" wire:model.blur="fields.{{ $i }}.label" maxlength="200" required
                                                    placeholder="{{ __('form_builder.field_label_placeholder') }}" @if ($labelBad) aria-invalid="true" aria-describedby="bm-l-{{ $key }}-e" @endif>
                                                @if ($labelBad)
                                                    <x-bs-admin::field-error id="bm-l-{{ $key }}-e" class="mt-1.5">{{ $fe }}</x-bs-admin::field-error>
                                                @endif
                                            </div>
                                            <div>
                                                <label for="bm-t-{{ $key }}" class="{{ $lab }}">{{ __('form_builder.field_type') }}</label>
                                                <select id="bm-t-{{ $key }}" class="field {{ $in }}" wire:model.live="fields.{{ $i }}.type">
                                                    @foreach ($kinds as $kind => $icon)
                                                        <option value="{{ $kind }}">{{ __('form_builder.type.'.$kind) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        @if ($f['type'] === 'select')
                                            <div>
                                                <label for="bm-o-{{ $key }}" class="{{ $lab }}">{{ __('form_builder.choices_label') }}</label>
                                                <textarea id="bm-o-{{ $key }}" rows="4" class="{{ $in }} py-2 text-body" wire:model.blur="fields.{{ $i }}.choices"
                                                    placeholder="{{ __('form_builder.choices_placeholder') }}" @if ($choicesBad) aria-invalid="true" aria-describedby="bm-o-{{ $key }}-e" @endif></textarea>
                                                @if ($choicesBad)
                                                    <x-bs-admin::field-error id="bm-o-{{ $key }}-e" class="mt-1.5">{{ $fe }}</x-bs-admin::field-error>
                                                @endif
                                            </div>
                                        @endif
                                        <label class="flex min-h-hit cursor-pointer items-center gap-2.5 text-body text-text">
                                            <input type="checkbox" class="size-4 shrink-0 accent-primary-text" wire:model.live="fields.{{ $i }}.required">
                                            <span>{{ __('form_builder.required_check') }}</span>
                                        </label>
                                        <x-bs-admin::button size="sm" variant="outline" color="danger" class="self-start" wire:click="removeField({{ Js::from($key) }})">
                                            <x-bs-admin::icon name="ri-delete-bin-6" class="size-4" /> {{ __('form_builder.remove_field') }}
                                        </x-bs-admin::button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        {{-- The consent box: the platform's sentence, required, always last; never edited here. --}}
                        <div role="listitem" @class(['flex items-start gap-2.5 bg-surface-muted p-3', 'border-t border-border' => $fields !== []])>
                            <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-5 shrink-0 text-text-muted" />
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <b class="font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $consentLabel }} <span class="text-primary-text underline">{{ __('form_builder.privacy') }}</span></b>
                                <span class="flex flex-wrap gap-1.5">
                                    <span class="inline-flex min-h-5.5 items-center gap-1 rounded-full bg-danger-soft px-2 py-0.5 text-caption font-semibold text-danger-text"><x-bs-admin::icon name="ri-checkbox" class="size-3.5" />{{ __('form_builder.consent_chip_required') }}</span>
                                    <span class="inline-flex min-h-5.5 items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-caption font-semibold text-text"><x-bs-admin::icon name="ri-lock" class="size-3.5" />{{ __('form_builder.consent_chip_locked') }}</span>
                                </span>
                                <small class="text-small text-text-muted">{{ __('form_builder.consent_note') }}</small>
                            </div>
                        </div>
                    </div>
                    @if (isset($shown['fields']))
                        <x-bs-admin::field-error id="bm-add-err" tabindex="-1">{{ $shown['fields']['msg'] }}</x-bs-admin::field-error>
                    @endif
                    @if (count($fields) < \App\Modules\Forms\Support\FormDraft::MAX_FIELDS)
                        <x-bs-admin::dropdown class="self-start">
                            <x-slot:trigger>
                                <x-bs-admin::button variant="outline" color="secondary" id="bm-add" :aria-describedby="isset($shown['fields']) ? 'bm-add-err' : null">
                                    <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('form_builder.add_field') }} <x-bs-admin::icon name="chevron-down" class="size-4" />
                                </x-bs-admin::button>
                            </x-slot:trigger>
                            @foreach ($kinds as $kind => $icon)
                                <button type="button" class="w-full gap-2.5 px-3 text-start text-text hover:bg-surface-hover" x-on:click="open = false" wire:click="addField({{ Js::from($kind) }})">
                                    <x-bs-admin::icon :name="$icon" class="size-4 text-text-muted" />{{ __('form_builder.type.'.$kind) }}
                                </button>
                            @endforeach
                        </x-bs-admin::dropdown>
                    @else
                        <p class="m-0 text-small text-text-muted">{{ __('form_builder.fields_max', ['max' => \App\Modules\Forms\Support\FormDraft::MAX_FIELDS]) }}</p>
                    @endif
                </div>
            </section>

            {{-- Sau khi khách gửi --}}
            <section class="{{ $card }}" aria-labelledby="bm-s3">
                <div class="{{ $head }}"><h2 id="bm-s3" class="{{ $h2 }}">{{ __('form_builder.card_after') }}</h2></div>
                <div class="flex min-w-0 flex-col gap-4 p-4">
                    <div>
                        <label for="bm-thx" class="{{ $lab }}">{{ __('form_builder.success_label') }}</label>
                        <textarea id="bm-thx" rows="3" class="{{ $in }} py-2 text-body" wire:model.blur="success" maxlength="1000"
                            placeholder="{{ __('form_builder.success_placeholder') }}" @if ($err('success')) aria-invalid="true" aria-describedby="bm-thx-e" @endif></textarea>
                        @if ($err('success'))
                            <x-bs-admin::field-error id="bm-thx-e" class="mt-1.5">{{ $err('success') }}</x-bs-admin::field-error>
                        @endif
                    </div>
                    <div>
                        <span id="bm-em-l" class="{{ $lab }}">{{ __('form_builder.notify_label') }}</span>
                        <div class="flex flex-wrap items-center gap-1.5" role="list" aria-labelledby="bm-em-l">
                            @forelse ($emails as $j => $email)
                                <span role="listitem" wire:key="bm-em-{{ $j }}-{{ md5($email) }}" class="inline-flex min-h-8 max-w-full items-center gap-1 rounded-full bg-surface-hover pe-1 ps-2.5 text-small text-text-strong [overflow-wrap:anywhere]">
                                    {{ $email }}
                                    <button type="button" class="hit-area inline-flex size-6 shrink-0 items-center justify-center rounded-full text-text-muted hover:bg-surface hover:text-text"
                                        aria-label="{{ __('form_builder.email_remove', ['email' => $email]) }}" title="{{ __('form_builder.email_remove', ['email' => $email]) }}" wire:click="removeEmail({{ $j }})">
                                        <x-bs-admin::icon name="ri-close" class="size-4" />
                                    </button>
                                </span>
                            @empty
                                <span role="listitem" class="inline-flex items-start gap-1.5 text-small text-warning-text"><x-bs-admin::icon name="ri-alert" class="mt-0.5 size-4 shrink-0" />{{ __('form_builder.notify_none') }}</span>
                            @endforelse
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <input type="email" inputmode="email" autocomplete="off" class="field {{ $in }} min-w-0 flex-[1_1_14rem]" wire:model="newEmail"
                                wire:keydown.enter.prevent="addEmail" aria-label="{{ __('form_builder.notify_input') }}" placeholder="{{ __('form_builder.notify_placeholder') }}"
                                @if ($emailError !== '') aria-invalid="true" aria-describedby="bm-em-e" @endif>
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="addEmail">
                                <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('form_builder.notify_add') }}
                            </x-bs-admin::button>
                        </div>
                        @if ($emailError !== '')
                            <x-bs-admin::field-error id="bm-em-e" class="mt-1.5">{{ $emailError }}</x-bs-admin::field-error>
                        @endif
                        <p class="{{ $hint }}">{{ __('form_builder.notify_hint') }}</p>
                    </div>
                </div>
            </section>

            {{-- Đặt biểu mẫu vào trang: how a form reaches the website, and where it is already --}}
            <section id="bm-s4" class="{{ $card }} scroll-mt-4" aria-labelledby="bm-s4-h">
                <div class="{{ $head }}"><h2 id="bm-s4-h" class="{{ $h2 }}">{{ __('form_builder.place_title') }}</h2></div>
                <div class="flex min-w-0 flex-col items-start gap-3 p-4">
                    <p class="m-0 text-body text-text [overflow-wrap:anywhere]">{{ __('form_builder.place_text', ['name' => trim($name) !== '' ? trim($name) : trim($saved['name'])]) }}</p>
                    @if ($pagePath !== null)
                        <p class="{{ $hint }} mt-0">{{ __('form_builder.place_on') }}
                            <a href="{{ $pagePath }}" target="_blank" rel="noopener" class="font-semibold text-primary-text underline underline-offset-2">{{ $pagePath }}</a></p>
                    @endif
                    @if ($pagesHref !== null)
                        <x-bs-admin::button variant="outline" color="secondary" :href="$pagesHref" wire:navigate>
                            <x-bs-admin::icon name="ri-layout-masonry" class="size-4" /> {{ __('form_builder.place_open') }}
                        </x-bs-admin::button>
                    @endif
                </div>
            </section>
        </div>

        {{-- Xem trước — khách thấy: the draft as the visitor will see it; nothing in it can be pressed. --}}
        <aside class="min-w-0 flex-[1_1_300px] lg:sticky lg:top-4" aria-labelledby="bm-pv-h">
            <section class="{{ $card }}">
                <div class="{{ $head }}">
                    <h2 id="bm-pv-h" class="{{ $h2 }}">{{ __('form_builder.preview_title') }}</h2>
                    <x-bs-admin::button size="sm" variant="ghost" color="primary" aria-pressed="{{ $showThanks ? 'true' : 'false' }}" wire:click="$toggle('showThanks')">
                        {{ $showThanks ? __('form_builder.preview_form') : __('form_builder.preview_thanks') }}
                    </x-bs-admin::button>
                </div>
                <div class="flex flex-col gap-3 bg-surface-muted p-4" inert>
                    <b class="text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong [overflow-wrap:anywhere]">{{ trim($name) }}</b>
                    @if ($showThanks)
                        <div class="flex gap-2 rounded-md bg-success-soft p-4 text-success-text">
                            <x-bs-admin::icon name="ri-checkbox-circle" class="size-5 shrink-0" /><span class="[overflow-wrap:anywhere]">{{ trim($success) !== '' ? trim($success) : $thanksDefault }}</span>
                        </div>
                    @else
                        @foreach ($fields as $f)
                            @php $pl = (trim($f['label']) !== '' ? trim($f['label']) : __('form_builder.field_unnamed')).($f['required'] ? ' *' : ''); @endphp
                            @if ($f['type'] === 'checkbox')
                                <span class="flex items-start gap-2 text-small font-semibold text-text-strong"><span class="mt-0.5 size-4 shrink-0 rounded-sm border border-border-input bg-surface"></span><span class="[overflow-wrap:anywhere]">{{ $pl }}</span></span>
                            @else
                                <span class="flex flex-col gap-1 text-small font-semibold text-text-strong">
                                    <span class="[overflow-wrap:anywhere]">{{ $pl }}</span>
                                    <span @class(['rounded border border-border-input bg-surface', 'min-h-18' => $f['type'] === 'textarea', 'min-h-9' => $f['type'] !== 'textarea'])></span>
                                </span>
                            @endif
                        @endforeach
                        <span class="flex items-start gap-2 text-small font-semibold text-text-strong"><span class="mt-0.5 size-4 shrink-0 rounded-sm border border-border-input bg-surface"></span><span class="[overflow-wrap:anywhere]">{{ $consentLabel }} <u>{{ $privacyLabel }}</u> *</span></span>
                        <span class="inline-flex min-h-10 items-center self-start rounded bg-primary px-4.5 font-semibold text-white">{{ $sendLabel }}</span>
                    @endif
                </div>
            </section>
        </aside>
    </div>

    <x-bs-admin::save-bar :changes="$changes" :invalid="$invalid" live first-error="bm-first-error" />
    <x-bs-admin::unsaved-dialog :count="$changes" :label="$title" save="save" discard="resetChanges" :body="$leave"
        :save-label="__('form_builder.leave_save')" :discard-label="__('form_builder.leave_discard')" />
    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" :undo="$removed !== null ? 'undoLast' : null" />
</div>
