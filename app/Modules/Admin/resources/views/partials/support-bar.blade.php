{{--
    The bar on top of a website's admin while platform staff are signed in as one of its members
    (design board 20 "Thanh trên trang quản trị của website", drawn by board 01's shell): who they are
    signed in as, the customer and the member's role, the time left, and "Quay lại quản trị", which
    ends the session (admin.support.leave) and goes back to the console. In the last two minutes the
    clock turns into "Sắp hết giờ mm:ss" on a danger fill; at 0:00 the page reloads and the server
    ends the session on record. Below 600px it keeps one line: the member's name, the clock, "Quay lại".
--}}
@php
    $bar = app(\App\Modules\Admin\Support\SupportBar::class)->facts();
@endphp
@if ($bar !== null)
    @php
        $full = $bar['role'] !== null
            ? __('admin.support.bar_as', ['member' => $bar['member'], 'customer' => $bar['customer'], 'role' => $bar['role']])
            : __('admin.support.bar_as_no_role', ['member' => $bar['member'], 'customer' => $bar['customer']]);
        $left = max(0, $bar['ends_at'] - now()->getTimestamp());
        $clock = sprintf('%02d:%02d', intdiv($left, 60), $left % 60);
    @endphp
    <div class="sticky top-0 z-30 flex h-12 shrink-0 items-center gap-2 border-b border-warning-text bg-[color-mix(in_srgb,var(--color-warning)_24%,var(--color-surface))] ps-3 pe-2 text-body text-text-strong min-[600px]:gap-2.5 min-[600px]:ps-4 min-[600px]:pe-3"
         role="region" aria-label="{{ __('admin.support.bar_region') }}" data-support-bar="1"
         x-data="bsSupportBar(@js(['left' => $left, 'words' => [
             'left' => __('admin.support.bar_left'),
             'soon' => __('admin.support.bar_soon'),
             'aria' => __('admin.support.bar_aria'),
         ]]))">
        <x-bs-admin::icon name="ri-user-shared" class="size-5 shrink-0 text-warning-text" />
        <span class="min-w-24 flex-1 truncate" title="{{ $full }}">
            <span class="max-[599.98px]:hidden">{{ $full }}</span>
            <span class="min-[600px]:hidden" aria-hidden="true">{{ $bar['member'] }}</span>
            <span class="sr-only min-[600px]:hidden">{{ $full }}</span>
        </span>
        <span role="timer" class="shrink-0 whitespace-nowrap font-semibold tabular-nums text-warning-text" :aria-label="aria">
            <span x-show="! warn" x-text="words.left.replace(':clock', clock)">{{ __('admin.support.bar_left', ['clock' => $clock]) }}</span>
            <span x-show="warn" x-cloak class="inline-flex items-center gap-1 rounded bg-danger-text px-2 py-0.5 text-surface">
                <x-bs-admin::icon name="ri-alarm-warning" class="size-4" /><span class="max-[599.98px]:hidden" x-text="words.soon"></span><span x-text="clock"></span>
            </span>
        </span>
        <form method="POST" action="{{ route('admin.support.leave') }}" class="shrink-0">
            @csrf
            <button type="submit" aria-label="{{ __('admin.support.bar_back_aria') }}"
                    class="hit-area inline-flex min-h-hit items-center gap-1.5 rounded border border-warning-text bg-surface px-3 text-small font-semibold text-warning-text transition-colors hover:bg-surface-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-warning-text">
                <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />
                <span class="max-[599.98px]:hidden">{{ __('admin.support.bar_back') }}</span>
                <span class="min-[600px]:hidden">{{ __('admin.support.bar_back_short') }}</span>
            </button>
        </form>
    </div>
@endif
