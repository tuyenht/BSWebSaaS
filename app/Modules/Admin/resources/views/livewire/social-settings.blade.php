@php
    // «Đăng nhập nhanh» (design board «Đăng nhập nhanh & liên kết tài khoản»): the platform face
    // (the shared Google/Facebook apps, super_admin) and the website face (its switch per provider,
    // and its own app folded away). Left: the providers, then the account-linking rule; right (under
    // them below 1000px of room): what the sign-in page shows with the switches as they stand.
    $platform = $scope === 'platform';
    $t = static fn (string $key, array $replace = []): string => __('admin.sl.'.$key, $replace);
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $small = 'size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0';
    $cardHead = 'text-caption font-bold uppercase tracking-[.04em] text-text-muted';
    $icons = ['google' => ['icon' => 'ri-google', 'color' => '#ea4335'], 'facebook' => ['icon' => 'ri-facebook-fill', 'color' => '#1877f2']];
    $chip = [
        'ok' => ['text-success-text', 'bg-success-base'],
        'error' => ['text-danger-text', 'bg-danger-base'],
        'none' => ['text-text-muted', 'border-[1.5px] border-dashed border-text-muted'],
    ];
    $shown = [];
    foreach ($apps as $key => $app) {
        $shown[$key] = $platform
            ? (bool) $app['connected'] && ($on[$key] ?? false) && $app['status'] !== 'error'
            : ((bool) $app['opened'] || (bool) $app['own_ready']) && ($on[$key] ?? false);
    }
    $quick = count(array_filter($shown));
@endphp
<x-bs-admin::settings-shell :active="$platform ? 'system-social' : 'social'" :unsaved="$changes">
    @if ($oauthError = \App\Modules\Admin\Support\OAuthError::messageFor(request()->query('oauth_error')))
        <div class="rounded border border-danger-base/25 bg-danger-soft px-4 py-3 text-body text-danger-text">{{ $oauthError }}</div>
    @endif

    <div class="@container/sl flex min-w-0 flex-col gap-4">
        @if ($platform)
            <div role="note" class="flex items-start gap-3 rounded-md border border-warning-base/45 bg-warning-base/12 px-4 py-3 text-body leading-relaxed text-text">
                <x-bs-admin::icon name="ri-error-warning" class="{{ $glyph }} mt-0.5 text-warning-text" />
                <p><b class="text-warning-text">{{ $t('plat_banner_lead') }}</b> {{ $t('plat_banner') }}</p>
            </div>
            <div class="flex items-start gap-3 rounded-md border border-info-base/40 bg-info-base/9 px-4 py-3 text-body leading-relaxed text-info-text">
                <x-bs-admin::icon name="ri-information" class="{{ $glyph }} mt-0.5" />
                <p>{!! $t('saas_banner', ['one' => '<b>'.e($t('one')).'</b>']) !!}</p>
            </div>
        @endif

        <div class="grid items-start gap-4.5 @min-[62.5rem]/sl:grid-cols-[minmax(0,1fr)_21.25rem]">
            <div class="flex min-w-0 flex-col gap-4">
                {{-- The providers --}}
                <section class="rounded-md border border-border-strong bg-surface shadow-card" aria-labelledby="sl-providers">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3.5">
                        <h2 id="sl-providers" class="{{ $cardHead }}">{{ $platform ? $t('providers_platform') : $t('providers_site') }}</h2>
                        @if ($platform)
                            <span class="inline-flex min-w-0 items-center gap-1.5 text-caption text-text-muted [overflow-wrap:anywhere]">
                                <x-bs-admin::icon name="ri-refresh" class="{{ $small }}" />{{ $t('auto_check') }}
                            </span>
                        @endif
                    </div>
                    <div class="px-4 py-1.5">
                        @foreach ($apps as $key => $app)
                            @php
                                $name = $t($key.'.name');
                                $isOn = (bool) ($on[$key] ?? false);
                                if ($platform) {
                                    $connected = (bool) $app['connected'];
                                    $kind = ['ok' => 'ok', 'unchecked' => 'ok', 'error' => 'error'][$app['status']] ?? 'none';
                                    $status = ['ok' => $t('st_connected'), 'unchecked' => $t('st_connected'), 'error' => $t('st_error')][$app['status']] ?? $t('st_none');
                                    $meta = $connected
                                        ? $t('users', ['count' => $app['users'], 'name' => $name]).($isOn ? '' : $t('users_off')).($app['status'] === 'unchecked' ? $t('unchecked', ['name' => $name]) : '')
                                        : $t('not_configured');
                                    $available = $connected;
                                } else {
                                    $available = (bool) $app['opened'] || (bool) $app['own_ready'];
                                    $kind = $available && $isOn ? 'ok' : 'none';
                                    $status = ! $available ? $t('st_closed') : ($isOn ? $t('st_on') : $t('st_off'));
                                    $meta = ! $available ? $t('meta_closed', ['name' => $name]) : ($isOn ? $t('meta_on') : $t('meta_off'));
                                }
                                $switchLabel = $t($isOn ? 'switch_off' : 'switch_on', ['name' => $name]).($platform ? $t('switch_all') : '');
                            @endphp
                            <div wire:key="sl-{{ $key }}" class="border-t border-border-soft first:border-t-0" x-data="{ cfg: false, busy: false }" x-on:bs-social-saved="cfg = false">
                                <div class="grid grid-cols-[1.625rem_minmax(0,1fr)_auto] items-center gap-x-3.5 gap-y-1.5 px-1 py-3.5 @min-[37.5rem]/sl:grid-cols-[1.625rem_minmax(0,1fr)_auto_auto]">
                                    <span class="row-span-2 self-start text-[1.5rem] leading-none @min-[37.5rem]/sl:row-span-1 @min-[37.5rem]/sl:self-center" style="color: {{ $icons[$key]['color'] }}">
                                        <x-bs-admin::icon :name="$icons[$key]['icon']" class="size-[1.625rem]" />
                                    </span>
                                    <div class="flex min-w-0 flex-col gap-0.5 @max-[37.4999rem]/sl:contents">
                                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                                            <h3 class="text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $name }}</h3>
                                            <span class="inline-flex items-center gap-1.5 text-caption font-semibold {{ $chip[$kind][0] }}">
                                                <span class="size-2 shrink-0 rounded-full {{ $chip[$kind][1] }}" aria-hidden="true"></span>{{ $status }}
                                            </span>
                                        </div>
                                        <p class="text-[calc(0.78125rem+var(--user-text-boost))] leading-snug text-text-muted @max-[37.4999rem]/sl:col-span-2 @max-[37.4999rem]/sl:col-start-2 @max-[37.4999rem]/sl:row-start-2">{{ $meta }}</p>
                                    </div>
                                    @if ($platform)
                                        <x-bs-admin::button variant="outline" color="primary" size="sm" x-on:click="cfg = ! cfg" x-bind:aria-expanded="cfg ? 'true' : 'false'" aria-controls="sl-cfg-{{ $key }}"
                                                            class="min-w-21 @max-[37.4999rem]/sl:col-span-3 @max-[37.4999rem]/sl:row-start-3 @max-[37.4999rem]/sl:w-full @min-[37.5rem]/sl:order-none">
                                            <span x-show="! cfg" class="inline-flex items-center gap-1.5"><x-bs-admin::icon :name="$connected ? 'ri-settings-3' : 'ri-add'" class="{{ $small }}" />{{ $connected ? $t('edit_config') : $t('config') }}</span>
                                            <span x-show="cfg" x-cloak class="inline-flex items-center gap-1.5"><x-bs-admin::icon name="ri-arrow-up-s" class="{{ $small }}" />{{ $t('close') }}</span>
                                        </x-bs-admin::button>
                                    @endif
                                    @if ($available)
                                        <span class="inline-flex justify-self-end @max-[37.4999rem]/sl:col-start-3 @max-[37.4999rem]/sl:row-start-1">
                                            <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $isOn ? 'true' : 'false' }}"
                                                    wire:click="toggle('{{ $key }}')" aria-label="{{ $switchLabel }}" title="{{ $switchLabel }}"></button>
                                        </span>
                                    @elseif (! $platform)
                                        <span class="inline-flex items-center gap-1.5 justify-self-end rounded-full border border-border bg-surface-hover px-2.5 py-0.5 text-caption font-semibold whitespace-nowrap text-text-muted @max-[37.4999rem]/sl:col-start-3 @max-[37.4999rem]/sl:row-start-1">
                                            <x-bs-admin::icon name="ri-lock" class="{{ $small }}" />{{ $t('locked') }}
                                        </span>
                                    @endif
                                </div>

                                @if ($platform && $app['status'] === 'error')
                                    <x-bs-admin::alert color="danger" icon="ri-error-warning" class="mx-1 mb-2.5 rounded-md border border-danger-base/35">
                                        <div class="min-w-0 leading-relaxed">
                                            {{ $t('error_box', ['name' => $name]) }}
                                            <x-bs-admin::button variant="outline" color="primary" size="sm" class="ms-2.5 mt-1 align-middle"
                                                                x-bind:disabled="busy" x-on:click="busy = true; $wire.recheck('{{ $key }}').then((r) => { busy = false; if (r === 'refused') { cfg = true } }, () => { busy = false })">
                                                <span x-show="! busy" class="inline-flex"><x-bs-admin::icon name="ri-refresh" class="{{ $small }}" /></span><span x-show="busy" x-cloak class="inline-flex"><x-bs-admin::icon name="ri-loader-4" class="{{ $small }} animate-spin motion-reduce:animate-none" /></span>{{ $t('recheck') }}
                                            </x-bs-admin::button>
                                        </div>
                                    </x-bs-admin::alert>
                                @endif

                                @if ($platform)
                                    <div id="sl-cfg-{{ $key }}" x-show="cfg" x-cloak class="mx-1 mb-3 rounded-md border border-[color-mix(in_srgb,var(--color-primary-text)_32%,transparent)] bg-surface-muted px-4.5 py-4">
                                        @include('admin::livewire.social-settings.platform-keys', ['key' => $key, 'app' => $app, 'name' => $name, 'rows' => $copyRows[$key], 'console' => $consoles[$key]])
                                    </div>
                                @elseif ((bool) $app['opened'] || (bool) $app['own'])
                                    @include('admin::livewire.social-settings.site-keys', ['key' => $key, 'app' => $app, 'name' => $name])
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @unless ($platform)
                        <div class="flex items-start gap-2 border-t border-border-soft bg-surface-muted px-4 py-3 text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted">
                            <x-bs-admin::icon name="ri-global" class="{{ $small }} mt-0.5" /><span>{{ $t('domain_note') }}</span>
                        </div>
                    @endunless
                </section>

                {{-- Linking a sign-in to an account with the same email: one rule for the whole platform. --}}
                <section class="rounded-md border border-border-strong bg-surface p-4 shadow-card" aria-labelledby="sl-link">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <h2 id="sl-link" class="{{ $cardHead }}">{{ $t('link_title') }}</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-hover px-2.5 py-0.5 text-caption font-semibold whitespace-nowrap text-text-muted">
                            <x-bs-admin::icon name="ri-shield-check" class="{{ $small }}" />{{ $t('link_locked') }}
                        </span>
                    </div>
                    <div class="flex items-start gap-3.5">
                        <span class="mt-2 size-2 shrink-0 rounded-full bg-success-base" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1 text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-strong">
                                {{ $t('link_rule') }}
                                <x-bs-admin::help-pop :label="$t('link_help_aria')">{{ $t('link_help') }}</x-bs-admin::help-pop>
                            </div>
                            <p class="mt-1.5 max-w-140 text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted">{{ $t('link_explain') }}</p>
                            <p class="mt-2 text-caption leading-relaxed text-text-muted">{{ $t('link_system') }}</p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- What the sign-in page shows, with the switches as they stand now --}}
            <aside class="@min-[62.5rem]/sl:sticky @min-[62.5rem]/sl:top-4" aria-labelledby="sl-preview">
                <div class="rounded-md border border-border-strong bg-surface p-4.5 shadow-card">
                    <h2 id="sl-preview" class="{{ $cardHead }} mb-3.5">{{ $t('preview_title') }}</h2>
                    <div class="flex flex-col gap-3 rounded-lg border border-border bg-surface-muted px-4 py-4.5" aria-hidden="true">
                        <div class="text-center text-[calc(0.9375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('auth.welcome') }}</div>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-caption font-medium text-text">{{ __('auth.email_label') }}</span>
                            <span class="flex items-stretch overflow-hidden rounded border border-border-strong bg-surface">
                                <span class="inline-flex items-center border-e border-border-strong bg-surface-hover px-2.5 text-caption text-text-muted">@</span><span class="h-8.5 flex-1"></span>
                            </span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-caption font-medium text-text">{{ __('auth.password_label') }}</span>
                                <span class="text-caption text-primary-text">{{ __('auth.forgot_password') }}</span>
                            </span>
                            <span class="block h-8.5 rounded border border-border-strong bg-surface"></span>
                        </div>
                        <div class="flex min-h-9.5 items-center justify-center rounded bg-primary text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-white">{{ __('auth.sign_in') }}</div>
                        @if ($quick > 0)
                            <div class="flex items-center gap-2.5 text-caption text-text-muted">
                                <span class="h-px flex-1 bg-border-strong"></span>{{ __('auth.or_continue') }}<span class="h-px flex-1 bg-border-strong"></span>
                            </div>
                            <div @class(['grid gap-2.5', 'grid-cols-2' => $quick > 1])>
                                @if ($shown['google'] ?? false)
                                    <div class="flex min-h-9.5 items-center justify-center gap-1.5 rounded border border-[#dadce0] bg-white text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-[#3c4043]">
                                        <x-bs-admin::icon name="ri-google" class="{{ $glyph }} text-[#ea4335]" />Google
                                    </div>
                                @endif
                                @if ($shown['facebook'] ?? false)
                                    <div class="flex min-h-9.5 items-center justify-center gap-1.5 rounded bg-[#1877f2] text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-white">
                                        <x-bs-admin::icon name="ri-facebook-fill" class="{{ $glyph }}" />Facebook
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                    <p class="mt-3 text-caption leading-relaxed text-text-muted">{{ $quick > 0 ? $t('preview_note') : $t('preview_none') }}</p>
                    <p class="mt-2 border-t border-border-soft pt-2.5 text-caption leading-relaxed text-text-muted">{!! $t('preview_hidden', ['hidden' => '<b>'.e($t('hidden')).'</b>']) !!}</p>
                </div>
            </aside>
        </div>
    </div>

    @php
        $askName = $askProvider !== '' ? $t($askProvider.'.name') : '';
        $askUsers = $platform && $askProvider !== '' ? (int) ($apps[$askProvider]['users'] ?? 0) : 0;
    @endphp
    <x-bs-admin::confirm-dialog model="askOpen" confirm="confirmAsk" cancel="cancelAsk" icon="ri-alert" danger
                                :title="$askKind === 'disconnect' ? $t('disc_title', ['name' => $askName]) : $t('off_title', ['name' => $askName])"
                                :ok="$askKind === 'disconnect' ? $t('disc_ok') : $t('off_ok', ['name' => $askName])"
                                :ok-icon="$askKind === 'disconnect' ? 'ri-link-unlink-m' : 'ri-toggle'">
        @if ($askKind === 'disconnect')
            <x-bs-admin::confirm-line icon="ri-link-unlink-m">{{ $t('disc_body', ['name' => $askName]) }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-information">{{ $t('disc_note') }}</x-bs-admin::confirm-line>
        @elseif ($askKind === 'off')
            <x-bs-admin::confirm-line icon="ri-toggle">{{ $platform ? $t('off_body_platform', ['count' => $askUsers, 'name' => $askName]) : $t('off_body_site', ['name' => $askName]) }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-information">{{ $t('off_note') }}</x-bs-admin::confirm-line>
        @endif
    </x-bs-admin::confirm-dialog>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
