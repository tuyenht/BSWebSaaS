<div class="auth-glass">
    <h2 class="auth-title">{{ __('auth.two_factor_title') }}</h2>
    <p style="text-align:center;color:rgba(100,116,139,0.85);font-size:13px;margin-bottom:18px">
        {{ $useRecovery ? __('auth.recovery_hint') : __('auth.two_factor_subtitle') }}
    </p>

    @error('code')
        <div class="auth-alert auth-alert--error" style="margin-bottom:14px">{{ $message }}</div>
    @enderror

    <form wire:submit="verify" class="auth-form">
        <div class="auth-input-group">
            <label class="auth-label" for="code">{{ $useRecovery ? __('auth.recovery_label') : __('auth.code_label') }}</label>
            <div class="auth-input-wrap">
                <div class="auth-input-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                    </svg>
                </div>
                <input id="code" type="text" wire:model="code" autofocus
                       autocomplete="one-time-code" inputmode="{{ $useRecovery ? 'text' : 'numeric' }}"
                       placeholder="{{ $useRecovery ? 'xxxxx-xxxxx' : '000000' }}"
                       class="auth-input auth-input--no-action-right">
            </div>
        </div>

        <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="verify">
            <span wire:loading.remove wire:target="verify">{{ __('auth.verify') }}</span>
            <svg wire:loading.remove wire:target="verify" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
            <svg wire:loading wire:target="verify" class="auth-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span wire:loading wire:target="verify">{{ __('auth.processing') }}</span>
        </button>
    </form>

    <div style="text-align:center;margin-top:14px">
        <button type="button" wire:click="$toggle('useRecovery')"
                style="color:#3b82f6;font-size:13px;background:none;border:0;cursor:pointer;padding:0">
            {{ $useRecovery ? __('auth.use_totp') : __('auth.use_recovery') }}
        </button>
    </div>
    <div style="text-align:center;margin-top:10px">
        <a href="{{ \App\Modules\Admin\Support\AdminPlane::route('login') }}" style="color:#64748b;font-size:13px;text-decoration:none">← {{ __('auth.back_to_login') }}</a>
    </div>
</div>
