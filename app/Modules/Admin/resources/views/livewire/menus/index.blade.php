{{--
    Trình đơn (design 11) — a working stub: the screen's data and every change come from the
    component's methods, called from script ($wire.load(), $wire.saveDraft(…)…). The editor the
    design draws is ported onto this page next; until then it shows the title and an empty state.
--}}
<div>
    <div class="mx-auto max-w-lg py-16 text-center">
        <span class="mx-auto mb-4 grid size-14 place-items-center rounded-full bg-surface-muted text-text-muted">
            <x-bs-admin::icon name="menu" class="size-7" />
        </span>
        <h1 class="text-lg font-semibold text-text-strong">{{ __('menus.title') }}</h1>
        <p class="mx-auto mt-2 max-w-md text-sm text-text-muted">{{ __('menus.stub_empty') }}</p>
    </div>
</div>
