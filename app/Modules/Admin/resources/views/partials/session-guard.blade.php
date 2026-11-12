{{--
    The admin frame's two connection notices (design board 25: "Phiên sắp hết hạn", "Mất kết nối"),
    driven by Alpine.data('bsSession') in resources/js/admin-session.js:
      - two minutes before the session would end for lack of activity, a dialog counts down with
        "Tiếp tục làm việc" (keeps the session; Esc does the same) and "Đăng xuất"; at 0:00 it says
        the session has ended and its button opens the sign-in again. Tab stays inside, and focus
        goes back where it was when it closes. Activity in any tab of this admin counts. Never shown
        while a support person is signed in as the member (the support bar reminds them).
      - offline: a strip at the top of the content, not over the save bar, with "Kiểm tra lại";
        back online, "Đã kết nối lại." for three seconds.
    A notice the server left for this page (an unknown address sent here) shows once as a toast.
--}}
@php
    $guardUser = auth()->user();
    $warn = $guardUser !== null && (\App\Modules\Admin\Support\AdminPlane::isPlatform()
        || app(\App\Modules\Identity\Support\SupportSession::class)->current() === null);
    [$warnBefore, $warnAfter] = array_pad(explode('@@', __('errors.warn.line', ['clock' => '@@']), 2), 2, '');
    $notice = session('admin.notice');
@endphp
<div x-data="bsSession(@js([
        'lifetime' => max(3, (int) config('session.lifetime')) * 60,
        'warn' => $warn,
        'ping' => $guardUser !== null ? \App\Modules\Admin\Support\AdminPlane::route('session.ping') : null,
        'expired' => \App\Modules\Admin\Support\AdminPlane::route('session.expired'),
        'words' => [
            'extended' => __('errors.warn.extended'),
            'logout' => __('errors.warn.logged_out'),
            'back' => __('errors.offline.back'),
            'still' => __('errors.offline.still'),
            'notice' => is_string($notice) ? $notice : null,
        ],
    ]))" class="contents">
    {{-- Offline / back online: under the top bar, above the page's own content. --}}
    <template x-if="net === 'off'">
        <div class="te-strip" role="status">
            <x-bs-admin::icon name="ri-wifi-off" class="size-5" />
            <span>{{ __('errors.offline.line') }}</span>
            <x-bs-admin::button size="sm" variant="ghost" x-on:click="recheck()">
                <x-bs-admin::icon name="ri-refresh" class="size-4" /><span>{{ __('errors.offline.recheck') }}</span>
            </x-bs-admin::button>
        </div>
    </template>
    <template x-if="net === 'ok'">
        <div class="te-strip" data-ok="1" role="status">
            <x-bs-admin::icon name="ri-wifi" class="size-5" />
            <span>{{ __('errors.offline.back') }}</span>
        </div>
    </template>

    @if ($warn)
        <template x-if="open">
            <div class="te-ov" x-on:keydown="onKey($event)">
                <div class="te-dlg" role="alertdialog" aria-modal="true" aria-labelledby="te-w-t" aria-describedby="te-w-d" x-ref="dlg">
                    <h2 class="te-dlg-t" id="te-w-t" x-text="ended ? @js(__('errors.expired.title')) : @js(__('errors.warn.title'))"></h2>
                    <p class="te-l" id="te-w-d">
                        <span x-show="! ended">{{ $warnBefore }}<span class="te-clock" x-text="clock"></span>{{ $warnAfter }}</span>
                        <span x-show="ended">{{ __('errors.warn.ended_line') }}</span>
                    </p>
                    <div class="te-dlg-a">
                        <x-bs-admin::button variant="outline" color="secondary" x-on:click="logout()">
                            <x-bs-admin::icon name="ri-logout-box-r" class="size-4" /><span>{{ __('errors.warn.logout') }}</span>
                        </x-bs-admin::button>
                        <x-bs-admin::button data-te-first x-on:click="extend()">
                            <span x-show="! ended" class="inline-flex items-center gap-2"><x-bs-admin::icon name="ri-play-circle" class="size-4" /><span>{{ __('errors.warn.continue') }}</span></span>
                            <span x-show="ended" class="inline-flex items-center gap-2"><x-bs-admin::icon name="ri-login-box" class="size-4" /><span>{{ __('errors.expired.login') }}</span></span>
                        </x-bs-admin::button>
                    </div>
                </div>
            </div>
        </template>
        <form method="POST" action="{{ \App\Modules\Admin\Support\AdminPlane::route('logout') }}" x-ref="logoutForm" hidden>@csrf</form>
    @endif
    <div class="sr-only" aria-live="polite" x-text="live"></div>
</div>
