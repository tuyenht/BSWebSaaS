<div class="auth-glass">
    <h2 class="auth-title">{{ __('rbac.invitation.page_title') }}</h2>

    @if ($mode === 'closed')
        {{-- Used, withdrawn or expired: say which, and what fixes it (a new invitation). --}}
        <div class="auth-alert auth-alert--error" role="alert" style="margin-bottom:14px">{{ __('rbac.invitation.error_'.$reason) }}</div>
    @else
        <p style="text-align:center;color:rgba(100,116,139,0.85);font-size:13px;margin-bottom:10px">
            {{ __('rbac.invitation.summary', ['inviter' => $inviterName ?? __('rbac.invitation.someone'), 'site' => $siteName]) }}
        </p>
        <p style="text-align:center;font-size:13px;margin-bottom:14px">
            {{ __('rbac.invitation.roles_label') }}: <strong>{{ implode(', ', $roleLabels) }}</strong>
        </p>
        @if ($invitation->message !== null)
            <p style="text-align:center;font-style:italic;color:rgba(100,116,139,0.85);font-size:13px;margin-bottom:14px">“{{ $invitation->message }}”</p>
        @endif

        @error('invitation')
            <div class="auth-alert auth-alert--error" role="alert" style="margin-bottom:14px">{{ $message }}</div>
        @enderror

        @if ($mode === 'join')
            <button type="button" wire:click="join" class="auth-submit" wire:loading.attr="disabled" wire:target="join">
                <span wire:loading.remove wire:target="join">{{ __('rbac.invitation.join', ['site' => $siteName]) }}</span>
                <span wire:loading wire:target="join">{{ __('auth.processing') }}</span>
            </button>
        @elseif ($mode === 'sign_in')
            <p style="text-align:center;font-size:13px;margin-bottom:14px">{{ __('rbac.invitation.sign_in_hint', ['email' => $invitation->email]) }}</p>
            <button type="button" wire:click="signIn" class="auth-submit">{{ __('rbac.invitation.sign_in') }}</button>
        @elseif ($mode === 'wrong_account')
            <div class="auth-alert auth-alert--error" role="alert" style="margin-bottom:14px">
                {{ __('rbac.invitation.wrong_account', ['invited' => $invitation->email, 'current' => $signedInAs]) }}
            </div>
            <button type="button" wire:click="signOut" class="auth-submit">{{ __('rbac.invitation.sign_out') }}</button>
        @else
            <p style="text-align:center;font-size:13px;margin-bottom:14px">{{ __('rbac.invitation.create_hint', ['email' => $invitation->email]) }}</p>

            <form wire:submit="createAccount" class="auth-form">
                {{-- Name --}}
                <div class="auth-input-group">
                    <label class="auth-label" for="name">{{ __('rbac.invitation.name_label') }}</label>
                    <div class="auth-input-wrap">
                        <div class="auth-input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle stroke-linecap="round" stroke-linejoin="round" cx="12" cy="7" r="4" />
                            </svg>
                        </div>
                        <input id="name" type="text" wire:model="name" autocomplete="name" autofocus
                               placeholder="{{ __('rbac.invitation.name_placeholder') }}" class="auth-input auth-input--no-action-right">
                    </div>
                    @error('name')
                        <p style="margin-top:6px;color:#ef4444;font-size:12px">{{ $message }}</p>
                    @enderror
                </div>

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

                <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="createAccount">
                    <span wire:loading.remove wire:target="createAccount">{{ __('rbac.invitation.create_account') }}</span>
                    <span wire:loading wire:target="createAccount">{{ __('auth.processing') }}</span>
                </button>
            </form>
        @endif
    @endif

    <div style="text-align:center;margin-top:18px">
        <a href="{{ route('admin.login') }}" style="color:#3b82f6;font-size:13px;text-decoration:none">← {{ __('auth.back_to_login') }}</a>
    </div>
</div>
