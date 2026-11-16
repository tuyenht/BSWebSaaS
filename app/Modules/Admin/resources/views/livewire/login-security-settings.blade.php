<x-bs-admin::settings-shell active="login-security" :unsaved="$changes" :invalid="count($errors->keys())">
    @if (session('status'))
        <div class="rounded border border-admin-success/30 bg-admin-success/10 px-4 py-3 text-sm text-admin-success-text">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
        <div class="border-b border-border px-5 py-3">
            <h2 class="text-[15px] font-semibold text-body-text">{{ __('admin.turnstile_login_title') }}</h2>
            <p class="mt-0.5 text-xs text-body-muted">{{ __('admin.turnstile_login_desc') }}</p>
        </div>

        <div class="space-y-4 p-5">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="turnstileLogin"
                       class="mt-0.5 h-4 w-4 rounded border-border-strong text-accent focus:ring-admin-primary/30">
                <span>
                    <span class="block text-sm font-medium text-body-text">{{ __('admin.turnstile_login_label') }}</span>
                    <span class="mt-0.5 block text-xs text-body-muted">{{ __('admin.turnstile_login_help') }}</span>
                </span>
            </label>

            <div class="rounded border border-admin-info/30 bg-admin-info/10 px-4 py-3 text-xs text-admin-info-text">{{ __('admin.turnstile_login_note') }}</div>
        </div>
    </form>
</x-bs-admin::settings-shell>
