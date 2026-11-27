{{-- «Thư gần đây» (board «Email», «Gửi thư» tab, last card): the newest mails of the website, a joined
     group of filters at the top right, a reason under every mail that did not leave, a footer that says
     how long the log is kept. --}}
@php
    $filters = [
        'all' => __('admin.em_rm_all'),
        'sent' => __('admin.em_rm_sent').' '.\Illuminate\Support\Number::format($counts['sent'], locale: app()->getLocale()),
        'failed' => __('admin.em_rm_failed').' '.\Illuminate\Support\Number::format($counts['failed'], locale: app()->getLocale()),
    ];
@endphp
<section class="@container overflow-hidden rounded-md border border-border-strong bg-surface shadow-card" aria-labelledby="em-rm-title">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-[13px]">
        <h2 id="em-rm-title" class="text-caption font-bold tracking-[.04em] text-text-muted uppercase">{{ __('admin.em_rm_title') }}</h2>
        <div role="group" aria-label="{{ __('admin.em_rm_filter') }}" class="flex max-w-full flex-wrap overflow-hidden rounded-md border border-border-strong">
            {{-- a full 44px on touch, like the board's --spacing-target: a hit-area ::after would be clipped by the group's rounded edge --}}
            @foreach ($filters as $k => $label)
                <button type="button" wire:key="rmf-{{ $k }}" wire:click="setFilter(@js($k))" aria-pressed="{{ $filter === $k ? 'true' : 'false' }}"
                        @class([
                            'min-h-control any-pointer-coarse:min-h-hit cursor-pointer border-e border-border-strong px-3 py-1.5 text-caption font-semibold whitespace-nowrap last:border-e-0',
                            'focus-visible:outline-2 focus-visible:-outline-offset-2',
                            'bg-primary text-on-primary focus-visible:outline-on-primary' => $filter === $k,
                            'bg-surface text-text-muted hover:bg-surface-hover focus-visible:outline-primary-text' => $filter !== $k,
                        ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div wire:loading.class="opacity-60" wire:target="setFilter">
        @forelse ($rows as $r)
            <div wire:key="rm-{{ $r['key'] }}" class="border-t border-border px-4 py-[11px] first:border-t-0">
                <div class="flex flex-wrap items-center gap-3">
                    <time datetime="{{ $r['iso'] }}" title="{{ $r['full'] }}" class="min-w-[3.875rem] shrink-0 text-caption whitespace-nowrap text-text-muted">{{ $r['at'] }}</time>
                    <span class="shrink-0 text-body font-medium text-text-strong">{{ $r['kind'] }}</span>
                    <span class="min-w-0 flex-[1_1_10rem] text-caption text-text-muted [overflow-wrap:anywhere] @max-[35rem]:hidden">{{ $r['to'] ?? __('admin.em_rm_to_unknown') }}</span>
                    <span @class([
                            'ms-auto shrink-0 rounded-full px-2.5 py-0.5 text-small font-semibold whitespace-nowrap',
                            'bg-success-base/12 text-success-text' => $r['sent'],
                            'bg-danger-base/12 text-danger-text' => ! $r['sent'],
                        ])>{{ $r['sent'] ? __('admin.em_rm_state_sent') : __('admin.em_rm_state_failed') }}</span>
                </div>
                @if ($r['reason'] !== null)
                    <p class="mt-[5px] min-w-0 text-caption leading-normal text-text-muted">{{ $r['reason'] }}</p>
                @endif
            </div>
        @empty
            <p class="px-4 py-[26px] text-center text-small leading-relaxed text-text-muted">{{ __($filter === 'all' ? 'admin.em_rm_empty_all' : 'admin.em_rm_empty', ['days' => \App\Modules\Admin\Livewire\EmailRecentMail::KEEP_DAYS]) }}</p>
        @endforelse
    </div>

    <div class="flex flex-wrap items-baseline justify-between gap-3 border-t border-border bg-surface-muted px-4 py-[11px] text-caption leading-normal text-text-muted">
        <span class="min-w-0">{{ __('admin.em_rm_foot') }}</span>
        <span class="whitespace-nowrap">{{ __('admin.em_rm_keep', ['days' => \App\Modules\Admin\Livewire\EmailRecentMail::KEEP_DAYS]) }}</span>
    </div>
</section>
