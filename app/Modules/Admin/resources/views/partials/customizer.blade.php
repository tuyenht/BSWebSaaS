{{--
    Floating Theme Customizer: launcher button (fixed bottom-right, hidden on small
    screens per Velzon) + right offcanvas panel. Per-USER overrides only — instant
    attribute flip via the `adminTheme` Alpine store, persisted through the plane's
    theme-preference endpoint. The platform console is a scheme-only plane, so it
    renders just the Scheme axis.

    State lives in the global Alpine store (survives wire:navigate morphs); this
    partial is pure template.
--}}
@php($planeIsPlatform = \App\Modules\Admin\Support\AdminPlane::isPlatform())
<div x-data class="contents">
    <button type="button" @click="$store.adminTheme.open = true"
            aria-label="{{ __('admin.customize_theme') }}"
            class="fixed bottom-6 end-6 z-40 hidden size-12 place-items-center rounded-full bg-admin-info text-white shadow-lg transition-transform hover:scale-105 md:grid">
        {{-- Slow continuous spin draws the eye to the launcher (Velzon parity); paused for reduced-motion. --}}
        <x-admin::icon name="settings" class="size-5 customizer-gear" />
    </button>

    <div x-show="$store.adminTheme.open" x-transition.opacity @click="$store.adminTheme.open = false" x-cloak
         class="fixed inset-0 z-40 bg-gray-900/40"></div>

    <section x-show="$store.adminTheme.open" x-cloak role="dialog" aria-modal="true"
             aria-label="{{ __('admin.customize_theme') }}"
             @keydown.escape.window="$store.adminTheme.open = false"
             x-transition:enter="transition-transform duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition-transform duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 end-0 z-50 flex w-80 max-w-full flex-col bg-surface shadow-card">
        {{-- Navy header: focus rings follow its white ink (the page accent is this very navy). --}}
        <header class="flex items-center gap-2 bg-admin-primary px-4 py-3 text-white [--focus-ring-color:currentColor]">
            <h2 class="text-sm font-semibold">{{ __('admin.customize_theme') }}</h2>
            <button type="button" @click="$store.adminTheme.open = false" aria-label="{{ __('admin.close') }}"
                    class="ms-auto grid size-8 place-items-center rounded transition-colors hover:bg-white/10">
                <x-admin::icon name="x" class="size-4" />
            </button>
        </header>

        <div class="flex-1 space-y-5 overflow-y-auto p-4">
            <fieldset>
                <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_scheme') }}</legend>
                <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_scheme_desc') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <x-admin::theme-option axis="scheme" value="light" name="ct-scheme" :label="__('admin.theme_light')" store />
                    <x-admin::theme-option axis="scheme" value="dark" name="ct-scheme" :label="__('admin.theme_dark')" store />
                </div>
            </fieldset>

            @unless ($planeIsPlatform)
                <fieldset>
                    <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_layout_width') }}</legend>
                    <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_layout_width_desc') }}</p>
                    <div class="mt-2 inline-flex rounded border border-border-strong p-0.5">
                        <template x-for="opt in ['fluid', 'boxed']" :key="opt">
                            <button type="button" @click="$store.adminTheme.set('layout_width', opt)"
                                    :class="$store.adminTheme.state.layout_width === opt ? 'bg-admin-primary text-white' : 'text-body-muted hover:bg-surface-hover'"
                                    class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                                    x-text="opt === 'fluid' ? @js(__('admin.theme_fluid')) : @js(__('admin.theme_boxed'))"></button>
                        </template>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_topbar') }}</legend>
                    <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_topbar_desc') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin::theme-option axis="topbar" value="light" name="ct-topbar" :label="__('admin.theme_light')" store />
                        <x-admin::theme-option axis="topbar" value="dark" name="ct-topbar" :label="__('admin.theme_dark')" store />
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_sidebar_size') }}</legend>
                    <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_sidebar_size_desc') }}</p>
                    <div class="mt-2 inline-flex rounded border border-border-strong p-0.5">
                        <template x-for="opt in ['lg', 'sm']" :key="opt">
                            <button type="button" @click="$store.adminTheme.set('sidebar_size', opt)"
                                    :class="$store.adminTheme.state.sidebar_size === opt ? 'bg-admin-primary text-white' : 'text-body-muted hover:bg-surface-hover'"
                                    class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                                    x-text="opt === 'lg' ? @js(__('admin.theme_size_default')) : @js(__('admin.theme_size_compact'))"></button>
                        </template>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_sidebar') }}</legend>
                    <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_sidebar_desc') }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin::theme-option axis="sidebar" value="dark" name="ct-sidebar" :label="__('admin.theme_dark')" store />
                        <x-admin::theme-option axis="sidebar" value="light" name="ct-sidebar" :label="__('admin.theme_light')" store />
                        <x-admin::theme-option axis="sidebar" value="gradient" name="ct-sidebar" :label="__('admin.theme_gradient')" store />
                        <x-admin::theme-option axis="sidebar" value="gradient-2" name="ct-sidebar" :label="__('admin.theme_gradient_2')" store />
                        <x-admin::theme-option axis="sidebar" value="gradient-3" name="ct-sidebar" :label="__('admin.theme_gradient_3')" store />
                        <x-admin::theme-option axis="sidebar" value="gradient-4" name="ct-sidebar" :label="__('admin.theme_gradient_4')" store />
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_sidebar_image') }}</legend>
                    <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_sidebar_image_desc') }}</p>
                    <div class="grid grid-cols-5 gap-2">
                        <template x-for="img in ['none', 'img-1', 'img-2', 'img-3', 'img-4']" :key="img">
                            <button type="button" @click="$store.adminTheme.set('sidebar_image', img)"
                                    :aria-label="img === 'none' ? false : (@js(__('admin.theme_sidebar_image')) + ' ' + img.replace('img-', ''))"
                                    :class="$store.adminTheme.state.sidebar_image === img ? 'ring-2 ring-accent' : 'border border-border'"
                                    class="grid aspect-[3/5] place-items-center overflow-hidden rounded bg-surface-muted text-[9px] text-body-muted"
                                    :style="img === 'none' ? '' : `background-image:url('/images/admin/sidebar/${img}.jpg');background-size:cover;background-position:center`"
                                    x-text="img === 'none' ? @js(__('admin.theme_image_none')) : ''"></button>
                        </template>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="text-[11px] font-semibold uppercase tracking-wider text-body-muted">{{ __('admin.theme_position') }}</legend>
                    <p class="mb-2 mt-0.5 text-xs text-body-muted">{{ __('admin.theme_position_desc') }}</p>
                    <div class="mt-2 inline-flex rounded border border-border-strong p-0.5">
                        <template x-for="pos in ['fixed', 'scrollable']" :key="pos">
                            <button type="button" @click="$store.adminTheme.set('position', pos)"
                                    :class="$store.adminTheme.state.position === pos ? 'bg-admin-primary text-white' : 'text-body-muted hover:bg-surface-hover'"
                                    class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                                    x-text="pos === 'fixed' ? @js(__('admin.theme_fixed')) : @js(__('admin.theme_scrollable'))"></button>
                        </template>
                    </div>
                </fieldset>
            @endunless
        </div>

        <footer class="border-t border-border p-3">
            <button type="button" @click="$store.adminTheme.reset()"
                    class="w-full rounded border border-border-strong px-3 py-2 text-sm font-medium text-body-text transition-colors hover:bg-surface-hover">
                {{ __('admin.theme_reset') }}
            </button>
        </footer>
    </section>
</div>
