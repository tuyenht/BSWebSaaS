<div class="auth-glass">
    <h2 class="auth-title">{{ __('auth.reset_title') }}</h2>
    <p style="text-align:center;color:rgba(100,116,139,0.85);font-size:13px;margin-bottom:18px">
        {{ __('auth.email_label') }}: <strong>{{ $email }}</strong>
    </p>

    @error('email')
        <div class="auth-alert auth-alert--error" style="margin-bottom:14px">{{ $message }}</div>
    @enderror

    <form wire:submit="resetPassword" class="auth-form">
        {{-- New password --}}
        <div x-data="{ show: false }">
            <div class="auth-input-group">
                <label class="auth-label" for="password">{{ __('auth.new_password') }}</label>
                <div class="auth-input-wrap">
                    <div class="auth-input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect stroke-linecap="round" stroke-linejoin="round" x="3" y="11" width="18" height="11" rx="2" ry="2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </div>
                    <input id="password" :type="show ? 'text' : 'password'" wire:model="password"
                           autocomplete="new-password" placeholder="••••••••" class="auth-input">
                    <div class="auth-input-action">
                        <button type="button" class="auth-eye-btn" @click="show = !show" aria-label="{{ __('auth.new_password') }}">
                            <svg x-show="!show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                            <svg x-show="show" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
                        </button>
                    </div>
                </div>
            </div>
            @error('password')
                <p style="margin-top:6px;color:#ef4444;font-size:12px">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirm password --}}
        <div x-data="{ show: false }">
            <div class="auth-input-group">
                <label class="auth-label" for="password_confirmation">{{ __('auth.confirm_password') }}</label>
                <div class="auth-input-wrap">
                    <div class="auth-input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect stroke-linecap="round" stroke-linejoin="round" x="3" y="11" width="18" height="11" rx="2" ry="2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </div>
                    <input id="password_confirmation" :type="show ? 'text' : 'password'" wire:model="password_confirmation"
                           autocomplete="new-password" placeholder="••••••••" class="auth-input">
                    <div class="auth-input-action">
                        <button type="button" class="auth-eye-btn" @click="show = !show" aria-label="{{ __('auth.confirm_password') }}">
                            <svg x-show="!show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                            <svg x-show="show" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="resetPassword">
            <span wire:loading.remove wire:target="resetPassword">{{ __('auth.reset_password') }}</span>
            <svg wire:loading.remove wire:target="resetPassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
            <svg wire:loading wire:target="resetPassword" class="auth-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span wire:loading wire:target="resetPassword">{{ __('auth.processing') }}</span>
        </button>
    </form>

    <div style="text-align:center;margin-top:18px">
        <a href="{{ route('admin.login') }}" style="color:#3b82f6;font-size:13px;text-decoration:none">← {{ __('auth.back_to_login') }}</a>
    </div>
</div>
