<div class="auth-glass">
    <h2 class="auth-title">{{ __('auth.welcome') }}</h2>

    {{-- One-shot confirmation flashed by the step that led here (password reset, email verified) —
         without it the user never learns that step worked and starts it over. --}}
    @if (session('status'))
        <div class="auth-alert auth-alert--success" role="status" style="margin-bottom:14px">{{ session('status') }}</div>
    @endif

    <form wire:submit="login" class="auth-form">
        {{-- Account / Email --}}
        <div class="auth-input-group">
            <label class="auth-label" for="email">{{ __('auth.email_label') }}</label>
            <div class="auth-input-wrap">
                <div class="auth-input-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle stroke-linecap="round" stroke-linejoin="round" cx="12" cy="7" r="4" />
                    </svg>
                </div>
                <input id="email" type="email" wire:model="email" autocomplete="username" autofocus
                       placeholder="{{ __('auth.email_placeholder') }}" class="auth-input">
                <div class="auth-input-action">
                    <div class="auth-at-symbol">{{ '@' }}</div>
                </div>
            </div>
        </div>

        {{-- Password --}}
        <div x-data="{ show: false }">
            <div class="auth-input-group">
                <label class="auth-label" for="password">{{ __('auth.password_label') }}</label>
                <div class="auth-input-wrap">
                    <div class="auth-input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect stroke-linecap="round" stroke-linejoin="round" x="3" y="11" width="18" height="11" rx="2" ry="2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </div>
                    <input id="password" :type="show ? 'text' : 'password'" wire:model="password"
                           autocomplete="current-password" placeholder="{{ __('auth.password_placeholder') }}" class="auth-input">
                    <div class="auth-input-action">
                        <button type="button" class="auth-eye-btn" @click="show = !show" aria-label="{{ __('auth.password_label') }}">
                            <svg x-show="!show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg x-show="show" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                                <line x1="1" y1="1" x2="23" y2="23" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="auth-forgot-link">
                <a href="{{ \App\Modules\Admin\Support\AdminPlane::route('password.request') }}">{{ __('auth.forgot_password') }}</a>
            </div>
        </div>

        {{-- Bot challenge (Cloudflare Turnstile) — dormant until TURNSTILE_SITE_KEY is set --}}
        @include('admin::partials.turnstile-field')

        {{-- Error banner (auth failure / rate limit are attached to the email field) --}}
        @error('email')
            <div class="auth-alert auth-alert--error">{{ $message }}</div>
        @enderror

        {{-- Submit --}}
        <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">{{ __('auth.sign_in') }}</span>
            <svg wire:loading.remove wire:target="login" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
            <svg wire:loading wire:target="login" class="auth-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span wire:loading wire:target="login">{{ __('auth.processing') }}</span>
        </button>
    </form>

    @unless (\App\Modules\Admin\Support\AdminPlane::isPlatform())
    {{-- Divider --}}
    <div class="auth-divider">
        <div class="auth-divider-line"></div>
        <div class="auth-divider-text"><span>{{ __('auth.or_continue') }}</span></div>
    </div>

    {{-- Quick login — each button is disabled until that provider's OAuth app is configured --}}
    @php
        $social = app(\App\Modules\Admin\Support\SocialAuth::class);
        $googleEnabled = $social->enabled('google');
        $facebookEnabled = $social->enabled('facebook');
    @endphp
    <div class="auth-social-grid">
        <a href="{{ $googleEnabled ? route('admin.oauth.start', ['purpose' => 'login', 'provider' => 'google']) : '#' }}"
           @class(['auth-social-btn', 'disabled' => ! $googleEnabled]) @if (! $googleEnabled) aria-disabled="true" tabindex="-1" @endif
           title="{{ $googleEnabled ? __('auth.login_with_google') : __('auth.not_configured') }}">
            <svg viewBox="0 0 24 24">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05" />
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
            </svg>
            <span>Google</span>
        </a>
        <a href="{{ $facebookEnabled ? route('admin.oauth.start', ['purpose' => 'login', 'provider' => 'facebook']) : '#' }}"
           @class(['auth-social-btn', 'disabled' => ! $facebookEnabled]) @if (! $facebookEnabled) aria-disabled="true" tabindex="-1" @endif
           title="{{ $facebookEnabled ? __('auth.login_with_facebook') : __('auth.not_configured') }}">
            <svg viewBox="0 0 24 24">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="#1877F2" />
            </svg>
            <span>Facebook</span>
        </a>
    </div>
    @endunless
</div>
