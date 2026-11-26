{{--
    «Chữ ký & thương hiệu» (design c-email-settings, the signature tab): the logo and the button
    colour every mail carries, then the signature at the foot of every mail (MailBrand draws both
    into the shared mail frame). Saved with the page's one save bar. The signature's editor is the
    lazy part bsEmailSig (admin-email-signature.js); everything else is plain Livewire.
--}}
@php
    $cap = 'text-[calc(0.75rem+var(--user-text-boost))] font-bold tracking-[.04em] text-text-muted uppercase';
    $sub = 'mt-0.5 text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted';
    $card = '@container rounded-md border border-border-strong bg-surface shadow-card';
    $sm = 'size-[min(1.25rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $layouts = [
        'left' => ['icon' => 'ri-layout-left', 'label' => __('admin.em_sig_layout_left')],
        'top' => ['icon' => 'ri-layout-top', 'label' => __('admin.em_sig_layout_top')],
        'text' => ['icon' => 'ri-text', 'label' => __('admin.em_sig_layout_text')],
    ];
    $noPick = __('admin.em_logo_no_rights');
    $vars = [];
    foreach (\App\Modules\Admin\Support\MailBrand::VARIABLES as $token) {
        $value = (string) ($sign['values'][$token] ?? '');
        $vars[$token] = $value !== '' ? __('admin.em_sig_var_sample', ['value' => $value]) : __('admin.em_sig_var_empty');
    }
    $cfg = [
        'layout' => $sigLayout,
        'html' => $sigHtml,
        'savedLayout' => $sigLayout,
        'savedHtml' => $sigHtml,
        'defaults' => $sign['defaults'],
        'texts' => ['linkBad' => __('admin.em_link_bad')],
    ];
    $inkColors = ['' => __('admin.em_color_default'), '#212529' => __('admin.em_color_dark')];
    foreach (\App\Modules\Admin\Support\MailBrand::SWATCHES as $hex => $key) {
        $inkColors[$hex] = __('admin.em_sw_'.$key);
    }
@endphp

{{-- Thương hiệu email --}}
<section class="{{ $card }}" aria-labelledby="em-brand-title">
    <div class="border-b border-border px-4 py-[13px]">
        <h2 id="em-brand-title" class="{{ $cap }}">{{ __('admin.em_brand_title') }}</h2>
        <p class="{{ $sub }}">{{ __('admin.em_brand_sub') }}</p>
    </div>
    <div class="px-4 py-3.5">
        <div class="flex flex-wrap items-center gap-5">
            <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2.5" data-picker-home>
                <button type="button" @if ($sign['canPick']) wire:click="pickLogo" @else disabled title="{{ $noPick }}" @endif
                        aria-label="{{ $sign['logo'] ? __('admin.em_logo_change') : __('admin.em_logo_pick') }}" data-picker-focus
                        @class([
                            'hit-area inline-flex h-9 w-[120px] shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded border text-[calc(0.75rem+var(--user-text-boost))] text-text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text disabled:cursor-not-allowed',
                            'border-border bg-surface' => $sign['logo'],
                            'border-dashed border-border-strong bg-surface-muted hover:border-primary-text hover:text-primary-text' => ! $sign['logo'],
                        ])>
                    @if ($sign['logo'])
                        <img src="{{ $sign['logo'] }}" alt="" class="max-h-full max-w-full object-contain">
                    @else
                        <x-bs-admin::icon name="ri-image-add" class="me-1 size-4" />{{ __('admin.em_logo_empty') }}
                    @endif
                </button>
                <span class="min-w-0 flex-[1_1_10rem] text-[calc(0.78125rem+var(--user-text-boost))] leading-snug text-text-muted [overflow-wrap:anywhere]">
                    {{ __('admin.em_logo_label') }}<br><span>{{ __('admin.em_logo_hint') }}</span>
                </span>
                @if ($sign['logo'])
                    <x-bs-admin::button type="button" variant="ghost" color="secondary" size="sm" wire:click="removeLogo">
                        <x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ __('admin.em_logo_remove') }}
                    </x-bs-admin::button>
                @endif
            </div>
            <span class="h-8 w-px shrink-0 self-center bg-border-strong @max-[40rem]:hidden" aria-hidden="true"></span>
            <div class="min-w-0 flex-[1_1_260px]">
                <span id="em-brand-color" class="text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.em_brand_color_label') }}</span>
                <div role="radiogroup" aria-label="{{ __('admin.em_brand_colors_aria') }}" class="mt-[7px] flex flex-wrap items-center gap-2"
                     x-data="{ step(el, by) { const r = [...$root.querySelectorAll('[role=radio]')]; const n = r[(r.indexOf(el) + by + r.length) % r.length]; n.focus(); n.click() } }">
                    @foreach ($sign['swatches'] as $sw)
                        <button type="button" role="radio" wire:key="sw-{{ $sw['hex'] }}" aria-checked="{{ $sw['on'] ? 'true' : 'false' }}" tabindex="{{ $sw['on'] ? 0 : -1 }}"
                                aria-label="{{ $sw['label'] }}" title="{{ $sw['label'] }}" wire:click="pickBrandColor(@js($sw['hex']))"
                                x-on:keydown.down.prevent="step($el, 1)" x-on:keydown.right.prevent="step($el, 1)"
                                x-on:keydown.up.prevent="step($el, -1)" x-on:keydown.left.prevent="step($el, -1)"
                                style="background: {{ $sw['hex'] }}; color: {{ $sw['ink'] }};"
                                @class([
                                    'hit-area inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full border-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text',
                                    'border-[#212529] shadow-[inset_0_0_0_2px_var(--color-surface)]' => $sw['on'],
                                    'border-black/10' => ! $sw['on'],
                                ])>
                            @if ($sw['on'])<x-bs-admin::icon name="ri-check" class="size-[calc(1rem+var(--user-text-boost))]" />@endif
                        </button>
                    @endforeach
                </div>
                <p class="mt-2 text-[calc(0.75rem+var(--user-text-boost))] leading-normal text-text-muted">{!! __('admin.em_brand_color_hint', ['all' => '<b>'.e(__('admin.em_brand_color_all')).'</b>']) !!}</p>
                @error('brandColor')<x-bs-admin::field-error class="mt-2">{{ $message }}</x-bs-admin::field-error>@enderror
            </div>
        </div>
        @error('logoKey')<x-bs-admin::field-error class="mt-2.5">{{ $message }}</x-bs-admin::field-error>@enderror
        @if ($sign['lowContrast'])
            <x-bs-admin::alert color="warning" role="status" icon="ri-error-warning" class="mt-2.5">{{ __('admin.em_brand_low') }}</x-bs-admin::alert>
        @endif
    </div>
</section>

{{-- Chữ ký email: the lazy editor part holds the layout buttons, the toolbar and the variables --}}
<section class="{{ $card }}" aria-labelledby="em-sig-title"
         x-ignore wire:ignore.self data-bs-lazy="bsEmailSig" data-bs-lazy-keep x-data="bsEmailSig({{ \Illuminate\Support\Js::from($cfg) }})">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-[13px]">
        <div>
            <h2 id="em-sig-title" class="{{ $cap }}">{{ __('admin.em_sig_title') }}</h2>
            <p class="{{ $sub }}">{{ __('admin.em_sig_sub') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div role="group" aria-label="{{ __('admin.em_sig_layouts_aria') }}" class="flex flex-wrap items-center gap-2">
                @foreach ($layouts as $k => $l)
                    <button type="button" wire:key="sl-{{ $k }}" aria-pressed="{{ $sigLayout === $k ? 'true' : 'false' }}"
                            x-bind:aria-pressed="layout === @js($k) ? 'true' : 'false'" x-on:click="pickLayout(@js($k))"
                            class="hit-area inline-flex cursor-pointer items-center gap-[7px] rounded border-[1.5px] border-border bg-surface px-3.5 py-2 text-[calc(0.8125rem+var(--user-text-boost))] font-medium whitespace-nowrap text-text hover:border-primary-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text aria-pressed:border-primary-text aria-pressed:bg-[color-mix(in_srgb,var(--color-primary-text)_5%,var(--color-surface))] aria-pressed:font-semibold aria-pressed:text-primary-text">
                        <x-bs-admin::icon :name="$l['icon']" class="{{ $sm }}" />{{ $l['label'] }}
                    </button>
                @endforeach
            </div>
            <button type="button" wire:click="resetSignature"
                    class="hit-area inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-border-strong bg-surface px-3 py-[7px] text-[calc(0.8125rem+var(--user-text-boost))] text-text hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text">
                <x-bs-admin::icon name="ri-arrow-go-back" class="{{ $sm }}" />{{ __('admin.em_sig_reset') }}
            </button>
        </div>
    </div>
    <div class="p-4">
        <p class="mb-3.5 flex items-start gap-[9px] rounded bg-surface-muted px-3.5 py-[11px] text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">
            <x-bs-admin::icon name="ri-links" class="mt-px size-4 shrink-0 text-primary-text" />
            <span>{!! __('admin.em_sig_auto', ['general' => '<b>'.e(__('admin.em_sig_auto_general')).'</b>']) !!}</span>
        </p>

        <p data-bs-lazy-wait x-show="false" class="mb-3 text-caption text-text-muted">{{ __('admin.em_sig_loading') }}</p>
        <x-bs-admin::alert color="danger" icon="ri-wifi-off" data-bs-lazy-error x-show="false" class="mb-3">
            <div class="flex flex-wrap items-center gap-2.5">
                {{ __('admin.em_sig_load_failed') }}
                <x-bs-admin::button size="sm" variant="outline" color="primary" data-bs-lazy-retry><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.em_sig_retry') }}</x-bs-admin::button>
            </div>
        </x-bs-admin::alert>

        <div class="relative rounded-md border border-border-strong">
            <div role="toolbar" aria-label="{{ __('admin.em_sig_tools_aria') }}" aria-controls="em-sig-ed"
                 class="flex flex-wrap items-center gap-0.5 rounded-t-md border-b border-border bg-surface-muted px-2.5 py-2">
                @php
                    $tool = 'hit-area inline-flex min-h-[calc(1.875rem+var(--user-text-boost))] cursor-pointer items-center justify-center gap-[5px] rounded border-0 bg-transparent px-2 text-[calc(0.8125rem+var(--user-text-boost))] whitespace-nowrap text-text-muted hover:bg-surface-hover hover:text-text-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-text disabled:cursor-not-allowed disabled:opacity-60 aria-expanded:bg-primary-soft aria-expanded:text-primary-text';
                @endphp
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_bold') }}" aria-label="{{ __('admin.em_tool_bold') }}" x-on:mousedown.prevent x-on:click="cmd('bold')"><x-bs-admin::icon name="ri-bold" class="{{ $sm }}" /></button>
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_italic') }}" aria-label="{{ __('admin.em_tool_italic') }}" x-on:mousedown.prevent x-on:click="cmd('italic')"><x-bs-admin::icon name="ri-italic" class="{{ $sm }}" /></button>
                <span class="mx-1 h-5 w-px shrink-0 bg-border-strong" aria-hidden="true"></span>
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_left') }}" aria-label="{{ __('admin.em_tool_left') }}" x-on:mousedown.prevent x-on:click="cmd('justifyLeft')"><x-bs-admin::icon name="ri-align-left" class="{{ $sm }}" /></button>
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_center') }}" aria-label="{{ __('admin.em_tool_center') }}" x-on:mousedown.prevent x-on:click="cmd('justifyCenter')"><x-bs-admin::icon name="ri-align-center" class="{{ $sm }}" /></button>
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_link') }}" aria-label="{{ __('admin.em_tool_link') }}" aria-haspopup="true" x-bind:aria-expanded="pop === 'link' ? 'true' : 'false'" x-on:mousedown.prevent x-on:click="toggle('link')"><x-bs-admin::icon name="ri-link" class="{{ $sm }}" /></button>
                <button type="button" class="{{ $tool }}" @if ($sign['canPick']) title="{{ __('admin.em_tool_image') }}" x-on:mousedown.prevent x-on:click="keep(); $wire.pickSignatureImage()" @else disabled title="{{ $noPick }}" @endif aria-label="{{ __('admin.em_tool_image') }}"><x-bs-admin::icon name="ri-image" class="{{ $sm }}" /></button>
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_color') }}" aria-label="{{ __('admin.em_tool_color') }}" aria-haspopup="true" x-bind:aria-expanded="pop === 'color' ? 'true' : 'false'" x-on:mousedown.prevent x-on:click="toggle('color')"><x-bs-admin::icon name="ri-font-color" class="{{ $sm }}" /></button>
                <button type="button" class="{{ $tool }}" aria-haspopup="true" x-bind:aria-expanded="pop === 'social' ? 'true' : 'false'"
                        @if ($sign['socials'] !== []) title="{{ __('admin.em_tool_social') }}" x-on:mousedown.prevent x-on:click="toggle('social')" @else disabled title="{{ __('admin.em_social_none') }}" @endif>
                    <x-bs-admin::icon name="ri-share" class="{{ $sm }}" /><span>{{ __('admin.em_tool_social') }}</span>
                </button>
                <button type="button" class="{{ $tool }}" title="{{ __('admin.em_tool_hr') }}" x-on:mousedown.prevent x-on:click="hr()"><x-bs-admin::icon name="ri-separator" class="{{ $sm }}" /><span>{{ __('admin.em_tool_hr') }}</span></button>
            </div>

            {{-- The toolbar's small panels: a link's address, the text colour, the website's social pages --}}
            <div x-show="pop !== ''" x-cloak x-on:keydown.escape.stop="close()" x-on:click.outside="pop = ''"
                 class="absolute inset-x-2.5 top-12 z-20 max-w-sm rounded-md border border-border-strong bg-surface p-3 shadow-card">
                <div x-show="pop === 'link'" class="flex flex-col gap-2">
                    <label for="em-link-url" class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.em_link_url') }}</label>
                    <input id="em-link-url" x-ref="linkInput" type="url" inputmode="url" x-model="linkUrl" x-on:keydown.enter.prevent="link()"
                           x-bind:aria-invalid="linkError ? 'true' : 'false'" aria-describedby="em-link-err" placeholder="https://"
                           class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text">
                    <p id="em-link-err" x-show="linkError" class="m-0 flex items-start gap-1.5 text-small text-danger-text" role="alert"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span x-text="linkError"></span></p>
                    <div class="flex flex-wrap gap-2">
                        <x-bs-admin::button type="button" size="sm" x-on:click="link()">{{ __('admin.em_link_insert') }}</x-bs-admin::button>
                        <x-bs-admin::button type="button" size="sm" variant="ghost" color="secondary" x-on:click="close()">{{ __('admin.cancel') }}</x-bs-admin::button>
                    </div>
                </div>
                <div x-show="pop === 'color'" role="group" aria-label="{{ __('admin.em_tool_color') }}" class="flex flex-wrap gap-2">
                    @foreach ($inkColors as $hex => $name)
                        <button type="button" x-on:mousedown.prevent x-on:click="color(@js($hex === '' ? '#5b6275' : $hex))" title="{{ $name }}" aria-label="{{ $name }}"
                                class="hit-area inline-flex size-7 cursor-pointer rounded-full border border-black/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text"
                                style="background: {{ $hex === '' ? '#5b6275' : $hex }};"></button>
                    @endforeach
                </div>
                <div x-show="pop === 'social'" class="flex flex-col gap-1">
                    @foreach ($sign['socials'] as $item)
                        <button type="button" x-on:mousedown.prevent x-on:click="social(@js($item))"
                                class="hit-area-row flex cursor-pointer items-center gap-2 rounded px-2 text-start text-[calc(0.8125rem+var(--user-text-boost))] text-text hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-primary-text">
                            <x-bs-admin::icon name="ri-links" class="size-4 shrink-0 text-text-muted" />{{ $item['name'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex"
                 x-bind:class="{ 'flex-col items-center gap-2 pt-4': layout === 'top', 'items-start gap-3.5 ps-4 pt-3': layout === 'left' }"
                 @class(['flex-col items-center gap-2 pt-4' => $sigLayout === 'top', 'items-start gap-3.5 ps-4 pt-3' => $sigLayout === 'left'])>
                <button type="button" x-show="layout !== 'text'" @if ($sigLayout === 'text') style="display: none" @endif
                        @if ($sign['canPick']) wire:click="pickLogo" @else disabled title="{{ $noPick }}" @endif
                        aria-label="{{ $sign['logo'] ? __('admin.em_logo_change') : __('admin.em_logo_pick') }}"
                        @class([
                            'hit-area inline-flex h-11 w-[140px] shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded border text-[calc(0.75rem+var(--user-text-boost))] text-text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text disabled:cursor-not-allowed',
                            'border-transparent' => $sign['logo'],
                            'border-dashed border-border-strong bg-surface-muted' => ! $sign['logo'],
                        ])>
                    @if ($sign['logo'])
                        <img src="{{ $sign['logo'] }}" alt="" class="max-h-full max-w-full object-contain">
                    @else
                        {{ __('admin.em_logo_box') }}
                    @endif
                </button>
                <div wire:ignore id="em-sig-ed" x-ref="ed" contenteditable="true" role="textbox" aria-multiline="true" aria-label="{{ __('admin.em_sig_editor') }}"
                     data-ph="{{ __('admin.em_sig_ph') }}" spellcheck="true"
                     x-on:input="changed()" x-on:blur="changed(true)" x-on:keyup="keep()" x-on:mouseup="keep()" x-on:paste="paste($event)"
                     class="min-h-[120px] min-w-0 flex-1 self-stretch px-4 py-[18px] text-[calc(0.875rem+var(--user-text-boost))] leading-relaxed text-text [overflow-wrap:anywhere] focus-visible:rounded focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text empty:before:text-text-muted empty:before:content-[attr(data-ph)] [&_a]:text-primary-text [&_a]:underline [&_hr]:my-2.5 [&_hr]:border-0 [&_hr]:border-t [&_hr]:border-border-strong [&_img]:inline-block [&_img]:max-w-full [&_.em-var]:mx-px [&_.em-var]:inline-block [&_.em-var]:rounded [&_.em-var]:border [&_.em-var]:border-border [&_.em-var]:bg-surface-muted [&_.em-var]:px-[7px] [&_.em-var]:py-px [&_.em-var]:text-[.92em] [&_.em-var]:font-semibold [&_.em-var]:whitespace-nowrap [&_.em-var]:text-primary-text"></div>
            </div>
        </div>
        @error('sigHtml')<x-bs-admin::field-error id="em-sig-err" class="mt-2">{{ $message }}</x-bs-admin::field-error>@enderror

        <div class="mt-3.5">
            <p id="em-sig-vars" class="mb-[9px] flex items-center gap-1.5 {{ $cap }}"><span class="font-mono" aria-hidden="true">{ }</span>{{ __('admin.em_sig_vars') }}</p>
            <div role="group" aria-labelledby="em-sig-vars" class="flex flex-wrap gap-2">
                @foreach ($vars as $token => $sample)
                    <button type="button" title="{{ $sample }}" aria-label="{{ $token }} — {{ $sample }}" x-on:mousedown.prevent x-on:click="variable(@js($token))"
                            class="hit-area cursor-pointer rounded-2xl border border-border bg-surface-muted px-3 py-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-medium whitespace-nowrap text-primary-text hover:border-primary-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text">{{ $token }}</button>
                @endforeach
            </div>
        </div>
    </div>
</section>
