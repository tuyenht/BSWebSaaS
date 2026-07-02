<x-admin::settings-shell active="appearance">
    <form wire:submit="save">
        <div class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
            <div class="border-b border-border px-5 py-3">
                <h2 class="text-[15px] font-semibold text-body-text">{{ __('admin.appearance') }}</h2>
                <p class="mt-0.5 text-xs text-body-faint">{{ __('admin.appearance_desc') }}</p>
            </div>

            <div class="space-y-6 p-5">
                @foreach ([
                    'scheme' => __('admin.theme_scheme_desc'),
                    'sidebar' => __('admin.theme_sidebar_desc'),
                    'topbar' => __('admin.theme_topbar_desc'),
                ] as $axis => $description)
                    <fieldset>
                        <legend class="text-sm font-medium text-body-text">{{ __('admin.theme_'.$axis) }}</legend>
                        <p class="mb-3 mt-0.5 text-xs text-body-faint">{{ $description }}</p>
                        <div class="grid max-w-md grid-cols-3 gap-3">
                            {{-- '' = inherit the app default; shown as its own card so "not pinned" stays visible. --}}
                            <label class="group block cursor-pointer">
                                <input type="radio" value="" wire:model="{{ $axis }}" class="peer sr-only">
                                <span class="grid h-14 place-items-center rounded border border-dashed border-border-strong text-[11px] font-medium text-body-faint transition-all peer-checked:border-solid peer-checked:border-accent peer-checked:ring-2 peer-checked:ring-accent/30">
                                    {{ __('admin.theme_inherit') }}
                                </span>
                                <span class="mt-1.5 block text-center text-xs font-medium text-body-muted group-has-[:checked]:text-accent">{{ __('admin.theme_default') }}</span>
                            </label>
                            @foreach (\App\Modules\Admin\Theme\AdminChromeResolver::FIELDS[$axis] as $value)
                                <x-admin::theme-option :axis="$axis" :value="$value" name="st-{{ $axis }}"
                                                       :label="__('admin.theme_'.$value)" wire:model="{{ $axis }}" />
                            @endforeach
                        </div>
                        @error($axis) <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
                    </fieldset>
                @endforeach

                <fieldset>
                    <legend class="text-sm font-medium text-body-text">{{ __('admin.theme_position') }}</legend>
                    <p class="mb-3 mt-0.5 text-xs text-body-faint">{{ __('admin.theme_position_desc') }}</p>
                    <div class="inline-flex rounded border border-border-strong p-0.5">
                        @foreach (['' => __('admin.theme_default'), 'fixed' => __('admin.theme_fixed'), 'scrollable' => __('admin.theme_scrollable')] as $value => $label)
                            <label @class([
                                'cursor-pointer rounded px-3 py-1.5 text-xs font-medium transition-colors',
                                'bg-admin-primary text-white' => $position === (string) $value,
                                'text-body-muted hover:bg-surface-hover' => $position !== (string) $value,
                            ])>
                                <input type="radio" value="{{ $value }}" wire:model.live="position" class="sr-only">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('position') <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
                </fieldset>
            </div>

            <div class="flex items-center gap-3 border-t border-border bg-surface-muted px-5 py-3">
                <x-admin::button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ __('admin.save') }}
                </x-admin::button>
                @if ($saved)
                    <span class="text-xs font-medium text-admin-success">{{ __('admin.theme_saved_note') }}</span>
                @endif
            </div>
        </div>
    </form>
</x-admin::settings-shell>
