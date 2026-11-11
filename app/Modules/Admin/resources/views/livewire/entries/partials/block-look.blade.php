{{--
    The "Trình bày" panel of one block (StyleProps; labels from the page-builder design): colour set,
    background photo, layout and the advanced choices. Each choice goes through Editor::setLook (a
    value that is not listed is ignored); the anchor is typed and made valid on blur.
    $i = block index, $block, $look = Editor::looks()[$i], $schemeColours = Editor::schemeColours().
--}}
@php
    $props = $block['props'];
    $photoBlock = in_array($block['type'], ['page_header', 'hero_slider'], true);
    $pressed = fn (string $prop, string $value): string => ($props[$prop] ?? null) === $value ? 'true' : 'false';
    $choiceRow = [
        'width' => ['narrow', 'contained', 'full'],
        'align' => ['left', 'center'],
    ];
    $hideOn = $props['hide_on'] ?? 'none';
    $motion = in_array($props['motion'] ?? null, \App\Modules\Theme\Components\StyleProps::MOTION, true) ? $props['motion'] : 'auto';
    $autoMotion = \App\Modules\Theme\Components\StyleProps::autoMotion($block['type']);
    $summary = __('entries.look_summary_hide_'.(in_array($hideOn, ['none', 'mobile', 'desktop'], true) ? $hideOn : 'none'))
        .' · '.__('entries.look_summary_motion_'.($motion === 'auto' ? $autoMotion : $motion))
        .(! empty($props['anchor_id']) ? ' · #'.$props['anchor_id'] : '');
@endphp
<fieldset class="block-look" wire:key="look-{{ $i }}">
    <legend>{{ __('entries.look_heading') }}</legend>

    <fieldset class="block-look__group">
        <legend>{{ __('entries.look_scheme') }}</legend>
        <div class="block-look__schemes">
            @foreach (\App\Modules\Theme\Components\StyleProps::SCHEMES as $set)
                @php $c = $schemeColours[$set]; @endphp
                <button type="button" class="block-look__scheme" wire:click="setLook({{ $i }}, 'scheme', '{{ $set }}')" aria-pressed="{{ $look['scheme'] === $set ? 'true' : 'false' }}">
                    <span class="block-look__sample" style="background:{{ $c['bg'] }};color:{{ $c['head'] }}" aria-hidden="true">
                        <b>{{ __('entries.look_sample_title') }}</b>
                        <span style="color:{{ $c['body'] }}">{{ __('entries.look_sample_body') }}</span>
                        <span style="background:{{ $c['button'] }};color:{{ $c['button_text'] }}">{{ __('entries.look_sample_button') }}</span>
                    </span>
                    <span>{{ __('entries.look_scheme_'.$set) }}</span>
                    <span class="block-look__hint">{{ __('entries.look_scheme_'.$set.'_desc') }}</span>
                </button>
            @endforeach
        </div>
        @if ($look['clash'])
            <p class="block-look__warn">{{ __('entries.look_scheme_clash', ['scheme' => __('entries.look_scheme_'.$look['scheme'])]) }}</p>
        @endif
        @if ($look['image'])
            <p class="block-look__hint">{{ __('entries.look_image_on_note') }}</p>
        @endif
        <p class="block-look__hint">{{ __('entries.look_scheme_note') }}</p>
    </fieldset>
    @unless ($photoBlock)
        <fieldset class="block-look__group">
            <legend>{{ __('entries.look_image') }}</legend>
            @if ($look['image'])
                @if (is_int($props['bg_image'] ?? null) && ! empty($blockImageUrls[$props['bg_image']]))
                    <img src="{{ $blockImageUrls[$props['bg_image']] }}" alt="" class="block-look__photo" width="160" height="90" style="width:160px;height:90px;object-fit:cover;display:block">
                @endif
                <div class="block-look__actions">
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openBlockImagePicker('{{ $i }}.props.bg_image')">{{ __('entries.look_image_change') }}</x-bs-admin::button>
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="removeLookImage({{ $i }})">{{ __('entries.look_image_remove') }}</x-bs-admin::button>
                </div>
                <div class="block-look__setting" role="group" aria-labelledby="look-{{ $i }}-overlay">
                    <span class="block-look__label" id="look-{{ $i }}-overlay">{{ __('entries.look_overlay') }}</span>
                    <div class="seg">
                        @foreach (['0', '75', '90'] as $value)
                            <button type="button" wire:click="setLook({{ $i }}, 'overlay', '{{ $value }}')" aria-pressed="{{ ($props['overlay'] ?? '0') === $value ? 'true' : 'false' }}">{{ __('entries.look_overlay_'.$value) }}</button>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="block-look__actions">
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openBlockImagePicker('{{ $i }}.props.bg_image')">{{ __('entries.look_image_add') }}</x-bs-admin::button>
                </div>
                <p class="block-look__hint">{{ __('entries.look_image_hint') }}</p>
            @endif
        </fieldset>
    @endunless

    <fieldset class="block-look__group">
        <legend>{{ __('entries.look_layout') }}</legend>
        @foreach ($look['split'] ? ['spacing_top', 'spacing_bottom'] : ['spacing'] as $row)
            <div class="block-look__setting" role="group" aria-labelledby="look-{{ $i }}-{{ $row }}">
                <span class="block-look__label" id="look-{{ $i }}-{{ $row }}">{{ __('entries.look_'.$row) }}</span>
                <div class="seg">
                    @foreach (['none', 's', 'm', 'l', 'xl'] as $value)
                        <button type="button" wire:click="setLook({{ $i }}, '{{ $row }}', '{{ $value }}')" aria-pressed="{{ $pressed($row === 'spacing' ? 'spacing_top' : $row, $value) }}">{{ __('entries.look_spacing_'.$value) }}</button>
                    @endforeach
                </div>
            </div>
        @endforeach
        @if (! isset($props['spacing_top']) && ! isset($props['spacing_bottom']))
            <p class="block-look__hint">{{ __('entries.look_spacing_unset') }}</p>
        @endif
        @foreach ($choiceRow as $prop => $values)
            <div class="block-look__setting" role="group" aria-labelledby="look-{{ $i }}-{{ $prop }}">
                <span class="block-look__label" id="look-{{ $i }}-{{ $prop }}">{{ __('entries.look_'.$prop) }}</span>
                <div class="seg">
                    @foreach ($values as $value)
                        <button type="button" wire:click="setLook({{ $i }}, '{{ $prop }}', '{{ $value }}')" aria-pressed="{{ ($props[$prop] ?? $values[$prop === 'width' ? 1 : 0]) === $value ? 'true' : 'false' }}">{{ __('entries.look_'.$prop.'_'.$value) }}</button>
                    @endforeach
                </div>
            </div>
        @endforeach
        <div>
            <button type="button" role="switch" class="switch-row" wire:click="toggleLookSplit({{ $i }})" aria-checked="{{ $look['split'] ? 'true' : 'false' }}">{{ __('entries.look_split') }}</button>
            @foreach (['corner', 'shadow'] as $prop)
                @php $on = ($props[$prop] ?? 'none') === 'soft'; @endphp
                <button type="button" role="switch" class="switch-row" wire:click="setLook({{ $i }}, '{{ $prop }}', '{{ $on ? 'none' : 'soft' }}')" aria-checked="{{ $on ? 'true' : 'false' }}">{{ __('entries.look_'.$prop) }}</button>
            @endforeach
        </div>
    </fieldset>

    {{-- wire:ignore.self: a choice re-renders the panel, and the section stays as the user left it. --}}
    <details class="block-look__group" wire:ignore.self>
        <summary>{{ __('entries.look_advanced') }} <span class="block-look__hint">{{ $summary }}</span></summary>
        <div class="block-look__setting" role="group" aria-labelledby="look-{{ $i }}-hide_on">
            <span class="block-look__label" id="look-{{ $i }}-hide_on">{{ __('entries.look_hide_on') }}</span>
            <div class="seg">
                @foreach (['none', 'desktop', 'mobile'] as $value)
                    <button type="button" wire:click="setLook({{ $i }}, 'hide_on', '{{ $value }}')" aria-pressed="{{ $hideOn === $value ? 'true' : 'false' }}">{{ __('entries.look_hide_on_'.$value) }}</button>
                @endforeach
            </div>
        </div>
        <div class="block-look__setting" role="group" aria-labelledby="look-{{ $i }}-motion">
            <span class="block-look__label" id="look-{{ $i }}-motion">{{ __('entries.look_motion') }}</span>
            <div class="seg">
                @foreach (['auto', 'none', 'fade', 'rise'] as $value)
                    <button type="button" wire:click="setLook({{ $i }}, 'motion', '{{ $value }}')" aria-pressed="{{ $motion === $value ? 'true' : 'false' }}">{{ __('entries.look_motion_'.$value) }}</button>
                @endforeach
            </div>
            @if ($look['first'])
                <p class="block-look__hint">{{ __('entries.look_motion_first') }}</p>
            @elseif ($motion === 'auto')
                <p class="block-look__hint">{{ __('entries.look_motion_auto_hint', ['effect' => __('entries.look_summary_motion_'.$autoMotion)]) }}</p>
            @endif
        </div>
        <label class="block-look__setting">
            <span class="block-look__label">{{ __('entries.look_anchor') }}</span>
            <span class="block-look__anchor">#<input type="text" wire:model.blur="blocks.{{ $i }}.props.anchor_id" placeholder="lien-he" class="field" maxlength="64" autocapitalize="off" spellcheck="false"></span>
        </label>
        @error("blocks.{$i}.props.anchor_id") <p class="error">{{ $message }}</p> @enderror
        <p class="block-look__hint">{{ __('entries.look_anchor_hint') }}</p>
    </details>
</fieldset>
