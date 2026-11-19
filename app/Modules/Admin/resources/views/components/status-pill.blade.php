@props([
    'status' => 'draft',
    'button' => false,
    'count' => false,
    'dot' => false,
])
@php
    // The one record-status pill of every list row (design .ql-st): a light tint of its own ink,
    // no visible border, 28 high at the default text size and growing with it, always lower than the row's
    // buttons. A screen picks only the status; tone, icon, height and padding live here so no
    // screen can make one look like an action button. `button` makes it the status menu's trigger:
    // a caret, a darker tint while hovered or open, and the shared 44 touch area around it.
    // The transparent border keeps the design's 9px inset and shows the outline in forced colours.
    // A label wraps only when the pill alone is wider than its cell (names are never clipped).
    // Unknown statuses read as plain text with no icon.
    // `count` draws the small number bubble of a status group (design .ql-tab .n): the same ink and
    // tint as the group's records, no glyph; "all" takes the accent ink and "trash" the danger ink.
    // `dot` draws only the status's colour as a small round mark (design .ql-dot), beside a status's
    // name in a status menu; it says nothing to a screen reader, the name beside it does.
    $tones = [
        // a website's own domain name: its state, and the main / platform / working-again marks
        'domain-wait' => ['[--c:var(--color-warning-text)]', 'ri-time'],
        'domain-claim' => ['[--c:var(--color-warning-text)]', 'ri-shield-user'],
        'domain-verified' => ['[--c:var(--color-info-text)]', 'ri-checkbox-circle'],
        'domain-issuing' => ['[--c:var(--color-info-text)]', 'ri-shield-keyhole'],
        'domain-https_err' => ['[--c:var(--color-danger-text)]', 'ri-error-warning'],
        'domain-active' => ['[--c:var(--color-success-text)]', 'ri-lock'],
        'domain-failing' => ['[--c:var(--color-warning-text)]', 'ri-link-unlink'],
        'domain-expiring' => ['[--c:var(--color-danger-text)]', 'ri-alarm-warning'],
        'domain-lost' => ['[--c:var(--color-danger-text)]', 'ri-close-circle'],
        'domain-primary' => ['[--c:var(--color-primary-text)]', 'ri-star-fill'],
        'domain-platform' => ['[--c:var(--color-text)]', 'ri-home-4'],
        'domain-recovered' => ['[--c:var(--color-success-text)]', 'ri-refresh'],
        'domain-locked' => ['[--c:var(--color-warning-text)]', 'ri-lock-2'],
        'published' => ['[--c:var(--color-success-text)]', 'ri-global'],
        'draft' => ['[--c:var(--color-text)]', 'ri-draft'],
        'scheduled' => ['[--c:color-mix(in_oklab,var(--color-secondary-text)_80%,var(--color-text-strong))]', 'ri-calendar-schedule'],
        'pending' => ['[--c:var(--color-warning-text)]', 'ri-inbox-unarchive'],
        'private' => ['[--c:var(--color-primary-text)]', 'ri-lock-2'],
        'archived' => ['[--c:var(--color-text-muted)]', 'ri-archive'],
        // the two groups of a record list that are not a status (only ever a count bubble)
        'all' => ['[--c:var(--color-primary-text)]', null],
        'trash' => ['[--c:var(--color-danger-text)]', null],
        // a customer or a website on the platform: running, paused, still being set up
        'active' => ['[--c:var(--color-success-text)]', 'ri-checkbox-circle'],
        'suspended' => ['[--c:var(--color-warning-text)]', 'ri-pause-circle'],
        'provisioning' => ['[--c:var(--color-info-text)]', 'ri-loader-4'],
        // a member invitation: waiting for an answer, past its expiry, withdrawn, taken up
        'invite-pending' => ['[--c:var(--color-warning-text)]', 'ri-mail-send'],
        'invite-expired' => ['[--c:var(--color-danger-text)]', 'ri-time'],
        'invite-revoked' => ['[--c:var(--color-text-muted)]', 'ri-close-circle'],
        'invite-accepted' => ['[--c:var(--color-success-text)]', 'ri-checkbox-circle'],
        // a support session: still open, or over (left, timed out, ended from the console…)
        'support-open' => ['[--c:var(--color-info-text)]', 'ri-user-shared'],
        'support-ended' => ['[--c:var(--color-text)]', 'ri-logout-box-r'],
    ];
    [$tone, $icon] = $tones[$status] ?? ['[--c:var(--color-text)]', null];

    $base = 'inline-flex min-h-7 max-w-full shrink-0 items-center gap-1.5 rounded-full border border-transparent px-2 py-0.5 text-small leading-[1.4] font-semibold text-(color:--c) bg-[color-mix(in_srgb,var(--c)_11%,var(--color-surface))]';
    $glyph = 'size-[calc(0.875rem+var(--user-text-boost))] shrink-0';
@endphp
@if ($count)
    <span data-s="{{ $status }}" {{ $attributes->merge(['class' => "inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-caption leading-normal font-semibold tabular-nums text-(color:--c) bg-[color-mix(in_srgb,var(--c)_11%,var(--color-surface))] $tone"]) }}>{{ $slot }}</span>
@elseif ($dot)
    <span data-s="{{ $status }}" aria-hidden="true" {{ $attributes->merge(['class' => "size-2 shrink-0 rounded-full bg-(color:--c) $tone"]) }}></span>
@elseif ($button)
    <button type="button" data-s="{{ $status }}" {{ $attributes->merge(['class' => "hit-area $base $tone cursor-pointer transition-colors hover:bg-[color-mix(in_srgb,var(--c)_18%,var(--color-surface))] aria-expanded:bg-[color-mix(in_srgb,var(--c)_18%,var(--color-surface))]"]) }}>
        @if ($icon)<x-bs-admin::icon :name="$icon" :class="$glyph" />@endif
        <span>{{ $slot }}</span>
        <x-bs-admin::icon name="ri-arrow-down-s" :class="$glyph.' opacity-80'" />
    </button>
@else
    <span data-s="{{ $status }}" {{ $attributes->merge(['class' => "$base $tone"]) }}>
        @if ($icon)<x-bs-admin::icon :name="$icon" :class="$glyph" />@endif
        <span>{{ $slot }}</span>
    </span>
@endif
