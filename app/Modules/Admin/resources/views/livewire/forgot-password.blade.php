<div class="auth-glass">
    <h2 class="auth-title">{{ __('auth.forgot_title') }}</h2>
    <p style="text-align:center;color:rgba(100,116,139,0.85);font-size:13px;margin-bottom:18px">
        {{ __('auth.forgot_subtitle') }}
    </p>

    @if ($status)
        <div class="auth-alert auth-alert--success" style="margin-bottom:14px">{{ $status }}</div>
    @endif
    @error('email')
        <div class="auth-alert auth-alert--error" style="margin-bottom:14px">{{ $message }}</div>
    @enderror

    <form wire:submit="submit" class="auth-form">
        <div class="auth-input-group">
            <label class="auth-label" for="email">{{ __('auth.email_label') }}</label>
            <div class="auth-input-wrap">
                <div class="auth-input-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                        <polyline stroke-linecap="round" stroke-linejoin="round" points="22,6 12,13 2,6" />
                    </svg>
                </div>
                <input id="email" type="email" wire:model="email" autocomplete="username" autofocus
                       placeholder="{{ __('auth.email_placeholder') }}" class="auth-input">
                <div class="auth-input-action">
                    <div class="auth-at-symbol">{{ '@' }}</div>
                </div>
            </div>
        </div>

        <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="submit">
            <span wire:loading.remove wire:target="submit">{{ __('auth.send_reset_link') }}</span>
            <svg wire:loading.remove wire:target="submit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
            <svg wire:loading wire:target="submit" class="auth-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span wire:loading wire:target="submit">{{ __('auth.processing') }}</span>
        </button>
    </form>

    <div style="text-align:center;margin-top:18px">
        <a href="{{ route('admin.login') }}" style="color:#3b82f6;font-size:13px;text-decoration:none">← {{ __('auth.back_to_login') }}</a>
    </div>
</div>
