{{--
    "Tổng quan" — design board 02, ported onto the admin tokens. Every card appears only for a
    member whose permissions and the website's modules allow what it links to (Dashboard.php);
    a card whose read failed shows plain words and "Thử lại" while the others stay right.
    The card container (@container/tq) sets the columns, as the board's `tq` container does.
--}}
@php
    use App\Modules\Tenancy\Context\SiteTime;
    use Illuminate\Support\Number;

    $locale = app()->getLocale();
    $siteTime = app(SiteTime::class);
    $nowAt = $siteTime->now();
    $num = fn (int $n): string => $n > $cap ? Number::format($cap, locale: $locale).'+' : Number::format($n, locale: $locale);
    $ago = function (?\DateTimeInterface $at) use ($siteTime, $nowAt, $locale): string {
        $t = $siteTime->forDisplay($at);

        return $t === null ? '' : $t->locale($locale)->diffForHumans($nowAt);
    };
    $stamp = function (?\DateTimeInterface $at) use ($siteTime): string {
        $t = $siteTime->forDisplay($at);

        return $t === null ? '' : __('dash.at', ['date' => $t->format('d/m/Y'), 'time' => $t->format('H:i')]);
    };
    $meta = fn (array $parts): string => implode(' · ', array_values(array_filter($parts, fn ($p): bool => is_string($p) && trim($p) !== '')));
    $statusLabel = fn (array $row): string => is_string($row['stage'] ?? null) && $row['stage'] !== '' ? $row['stage'] : __('entries.status_'.$row['status']);
    $typeIcon = fn (array $row): string => ($row['type'] ?? '') === 'page' ? 'ri-file-text' : 'ri-article';

    // The board's shared pieces, on the admin tokens.
    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card @container';
    $ch = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $h2 = 'text-[calc(1rem+var(--user-text-boost))] font-semibold leading-[1.4] text-text-strong';
    $btn = 'hit-area inline-flex min-h-control max-w-full shrink-0 items-center justify-center gap-1.5 rounded border border-border-input bg-surface px-3.5 py-1.5 text-body font-medium leading-tight text-text transition-colors hover:bg-surface-hover hover:text-text-strong';
    $ghost = 'hit-area inline-flex min-h-control max-w-full shrink-0 items-center justify-center gap-1.5 rounded px-3.5 py-1.5 text-body font-medium leading-tight text-primary-text transition-colors hover:bg-primary/10';
    $chip = 'inline-flex min-h-[1.375rem] items-center gap-1 rounded-full px-2 text-caption font-semibold leading-snug';
    $tones = [
        'info' => 'bg-info-soft text-info-text',
        'warn' => 'bg-warning-soft text-warning-text',
        'danger' => 'bg-danger-soft text-danger-text',
        'ok' => 'bg-success-soft text-success-text',
        '' => 'bg-primary-soft text-primary-text',
    ];
    $tic = 'inline-flex size-9 shrink-0 items-center justify-center rounded-full';
    $tt = 'font-semibold leading-[1.45] text-text-strong [overflow-wrap:break-word]';
    $ts = 'text-small leading-normal text-text-muted [overflow-wrap:anywhere]';
    $li = 'grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2.5 border-t border-border px-4 py-2.5 first:border-t-0 @max-[27.4375rem]:grid-cols-[auto_minmax(0,1fr)] @max-[27.4375rem]:[&>a]:col-start-2 @max-[27.4375rem]:[&>a]:justify-self-start';
    $lm = 'flex flex-wrap items-center gap-x-2 gap-y-1 text-small text-text-muted';
    $foot = 'flex flex-wrap gap-2 border-t border-border px-4 py-3';
    $empty = 'flex flex-col items-start gap-2 p-4 text-text-muted';

    // 1 · Việc cần làm hôm nay
    $todoItems = [];
    $leads = $todo['data']['leads'] ?? null;
    if (is_array($leads) && $leads['count'] > 0) {
        $latest = $leads['latest'];
        $todoItems[] = [
            'icon' => 'ri-mail-unread', 'tone' => 'info',
            'text' => __('dash.todo_leads', ['n' => $num($leads['count'])]),
            'sub' => $latest === null ? '' : ($latest['name'] !== ''
                ? __('dash.todo_leads_sub', ['name' => $latest['name'], 'form' => $latest['form'], 'when' => $ago($latest['at'])])
                : __('dash.todo_leads_sub_anon', ['form' => $latest['form'], 'when' => $ago($latest['at'])])),
            'btn' => __('dash.todo_leads_btn'), 'url' => route('admin.forms.index', ['status' => 'new']),
        ];
    }
    $rev = $review !== null && $review['ok'] ? $review['data'] : null;
    if ($rev !== null && $rev['queue_n'] > 0) {
        $todoItems[] = [
            'icon' => 'ri-inbox-archive', 'tone' => 'warn',
            'text' => __('dash.todo_review', ['n' => $rev['queue_more'] ? $num($cap + 1) : $num($rev['queue_n'])]),
            'sub' => $rev['oldest'] !== null && ! $rev['queue_more'] ? __('dash.todo_review_sub', ['title' => $rev['oldest']]) : '',
            'btn' => __('dash.todo_review_btn'), 'url' => route('admin.entries.review'),
        ];
    }
    $wr = $mine !== null && $mine['ok'] ? $mine['data'] : null;
    if ($wr !== null && $wr['returned'] !== []) {
        $first = $wr['returned'][0];
        $todoItems[] = [
            'icon' => 'ri-arrow-go-back', 'tone' => 'danger',
            'text' => __('dash.todo_returned', ['n' => $num(count($wr['returned']))]),
            'sub' => __('dash.todo_returned_sub', ['title' => $first['title'], 'reason' => $first['reason']]),
            'btn' => __('dash.todo_returned_btn'), 'url' => $first['url'],
        ];
    }
    foreach ($domains as $d) {
        $by = $d['by'] !== null ? $siteTime->forDisplay($d['by'])?->format('d/m/Y') : null;
        $todoItems[] = [
            'icon' => 'ri-global', 'tone' => 'danger',
            'text' => $d['state'] === 'lost' ? __('dash.todo_domain_lost', ['name' => $d['name']]) : __('dash.todo_domain', ['name' => $d['name']]),
            'sub' => match (true) {
                $d['state'] === 'lost' => __('dash.todo_domain_lost_sub', ['name' => $d['name']]),
                $by !== null && $d['state'] === 'wait' => __('dash.todo_domain_wait_by', ['date' => $by]),
                $by !== null => __('dash.todo_domain_by', ['date' => $by, 'name' => $d['name']]),
                default => '',
            },
            'btn' => __('dash.todo_domain_btn'), 'url' => route('admin.settings.domains'),
        ];
    }
    $todoFailed = ! $todo['ok'];

    // 2 · Bắt đầu: the steps not done yet; the ring counts every step.
    $steps = $start !== null && $start['ok'] ? $start['data'] : [];
    $stepsLeft = array_values(array_filter($steps, fn (array $s): bool => ! $s['done']));
    $stepsDone = count($steps) - count($stepsLeft);
    $startShow = $start !== null && ! $startHidden && (! $start['ok'] || $stepsLeft !== []);
    $ringC = 2 * M_PI * 26;
    $ringDash = $steps === [] ? 0 : $ringC * $stepsDone / count($steps);

    // 3 · Duyệt bài: the three tabs.
    $revTabs = $rev === null ? [] : [
        'queue' => ['label' => __('dash.rev_tab_queue'), 'n' => $rev['queue_more'] ? $num($cap + 1) : $num($rev['queue_n']), 'rows' => $rev['queue'], 'empty' => __('dash.rev_empty_queue')],
        'mine' => ['label' => __('dash.rev_tab_mine'), 'n' => $num($rev['mine_n']), 'rows' => $rev['mine'], 'empty' => __('dash.rev_empty_mine')],
        'sched' => ['label' => __('dash.rev_tab_sched'), 'n' => $num($rev['sched_n']), 'rows' => $rev['sched'], 'empty' => __('dash.rev_empty_sched')],
    ];
    $schedMore = $rev === null ? 0 : max(0, $rev['sched_n'] - count($rev['sched']));

    // 4 · Bài của tôi: the member's own posts, by status, in the posts list.
    $postsList = fn (array $q = []): string => route('admin.entries.index', array_merge($postsSection !== null ? ['section' => $postsSection] : [], $q));
    $tileTone = ['draft' => '', 'pending' => 'warn', 'scheduled' => 'info', 'published' => 'ok'];
    $tileIcon = ['draft' => 'ri-draft', 'pending' => 'ri-inbox-unarchive', 'scheduled' => 'ri-calendar-schedule', 'published' => 'ri-global'];
@endphp
<div class="@container/tq mx-auto flex w-full max-w-[80rem] flex-col gap-4">
    <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
        <div class="min-w-0 flex-[1_1_20rem]">
            <h1 class="text-title font-semibold leading-[1.4] text-text-strong">{{ __('admin.dashboard') }}</h1>
            @if ($siteName !== '')
                <p class="mt-1 text-text-muted [text-wrap:pretty]">{!! __('dash.desc', ['site' => '<strong class="font-semibold text-text">'.e($siteName).'</strong>']) !!}</p>
            @endif
        </div>
    </div>

    {{-- 1 · Việc cần làm hôm nay --}}
    @if ($todoItems !== [] || $todoFailed)
        <section class="{{ $card }}" aria-labelledby="tq-h-todo">
            <div class="{{ $ch }}">
                <h2 id="tq-h-todo" class="{{ $h2 }}">{{ __('dash.todo_title') }}</h2>
                @if ($todoItems !== [])
                    <span class="{{ $chip }} {{ $tones['warn'] }}"><x-bs-admin::icon name="ri-checkbox-multiple" class="size-[calc(0.875rem+var(--user-text-boost))]" />{{ __('dash.todo_count', ['n' => $num(count($todoItems))]) }}</span>
                @endif
            </div>
            @if ($todoFailed)
                @include('admin::livewire.dashboard.card-error', ['card' => 'todo', 'label' => __('dash.todo_title'), 'btn' => $btn])
            @endif
            <div>
                @foreach ($todoItems as $t)
                    <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 border-t border-border px-4 py-3 first:border-t-0 @max-[32.4375rem]:grid-cols-[auto_minmax(0,1fr)] @max-[32.4375rem]:[&>a]:col-span-full @max-[32.4375rem]:[&>a]:w-full">
                        <span class="{{ $tic }} {{ $tones[$t['tone']] }}" aria-hidden="true"><x-bs-admin::icon :name="$t['icon']" class="size-[calc(1.0625rem+var(--user-text-boost))]" /></span>
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <div class="{{ $tt }}">{{ $t['text'] }}</div>
                            @if ($t['sub'] !== '')
                                <div class="{{ $ts }}">{{ $t['sub'] }}</div>
                            @endif
                        </div>
                        <a href="{{ $t['url'] }}" wire:navigate class="{{ $btn }}">
                            <span>{{ $t['btn'] }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" />
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 2 · Bắt đầu với website của bạn --}}
    @if ($startShow)
        <section class="{{ $card }}" aria-labelledby="tq-h-start">
            <div class="{{ $ch }}">
                <h2 id="tq-h-start" class="{{ $h2 }}">{{ __('dash.start_title') }}</h2>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="hideStart" wire:loading.attr="disabled" wire:target="hideStart" class="{{ $ghost }}">
                        <x-bs-admin::icon name="ri-eye-off" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('dash.start_hide') }}</span>
                    </button>
                </div>
            </div>
            @if (! $start['ok'])
                @include('admin::livewire.dashboard.card-error', ['card' => 'start', 'label' => __('dash.start_title'), 'btn' => $btn])
            @else
                <div class="flex min-w-0 flex-col gap-4 p-4">
                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
                        <div class="relative size-16 shrink-0" role="img" aria-label="{{ __('dash.start_aria', ['done' => $stepsDone, 'total' => count($steps)]) }}">
                            <svg width="64" height="64" viewBox="0 0 64 64" aria-hidden="true" class="block -rotate-90">
                                <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" class="stroke-surface-hover"></circle>
                                <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" stroke-linecap="round" class="stroke-primary" stroke-dasharray="{{ number_format($ringDash, 1, '.', '') }} {{ number_format($ringC, 1, '.', '') }}"></circle>
                            </svg>
                            <b aria-hidden="true" class="absolute inset-0 flex items-center justify-center text-body font-bold text-text-strong">{{ $stepsDone }}/{{ count($steps) }}</b>
                        </div>
                        <div class="min-w-0">
                            <div class="{{ $tt }}">{{ __('dash.start_progress', ['done' => $stepsDone, 'total' => count($steps)]) }}</div>
                            <div class="{{ $ts }}">{{ __('dash.start_hint') }}</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,15.625rem),1fr))] gap-3">
                        @foreach ($stepsLeft as $s)
                            <div class="flex min-w-0 flex-col items-start gap-2 rounded-md border border-border bg-surface p-3">
                                <div class="{{ $tt }}">{{ __('dash.step_'.$s['key']) }}</div>
                                <div class="{{ $ts }}">{{ __('dash.step_'.$s['key'].'_sub') }}</div>
                                <a href="{{ $s['url'] }}" wire:navigate class="{{ $btn }}">
                                    <span>{{ __('dash.step_'.$s['key'].'_btn') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" />
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- Làm nhanh · Cần giúp? --}}
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16.25rem),1fr))] items-start gap-4">
        @if ($quick !== [])
            <section class="{{ $card }}" aria-labelledby="tq-h-quick">
                <div class="{{ $ch }}"><h2 id="tq-h-quick" class="{{ $h2 }}">{{ __('dash.quick_title') }}</h2></div>
                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,10rem),1fr))] gap-2 p-4">
                    @foreach ($quick as $q)
                        <a href="{{ $q['url'] }}" wire:navigate class="{{ $btn }} w-full justify-start">
                            <x-bs-admin::icon :name="$q['icon']" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ $q['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
        <section class="{{ $card }}" aria-labelledby="tq-h-help">
            <div class="{{ $ch }}"><h2 id="tq-h-help" class="{{ $h2 }}">{{ __('dash.help_title') }}</h2></div>
            <div class="flex min-w-0 flex-col gap-2 p-4">
                <p class="{{ $ts }}">{{ __('dash.help_text') }}</p>
                @foreach ([['tel', 'phone', 'ri-phone', 'dash.help_call', false], ['zaloHref', 'zalo', 'ri-chat-3', 'dash.help_zalo', true], ['mailto', 'email', 'ri-mail', 'dash.help_mail', false]] as [$href, $value, $icon, $label, $blank])
                    @if ($help[$href] !== '')
                        <a href="{{ $help[$href] }}" @if ($blank) target="_blank" rel="noopener" @endif
                           class="flex min-h-hit min-w-0 items-center gap-2.5 rounded-md border border-border px-3 py-2 text-text transition-colors hover:bg-surface-hover hover:text-text-strong">
                            <x-bs-admin::icon :name="$icon" class="size-[calc(1.0625rem+var(--user-text-boost))] shrink-0 text-primary-text" />
                            <span class="min-w-0 flex-auto">{{ __($label) }}</span>
                            <strong class="text-right font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $help[$value] }}</strong>
                        </a>
                    @endif
                @endforeach
                @if ($start !== null && $startHidden)
                    <button type="button" wire:click="showStart" wire:loading.attr="disabled" wire:target="showStart"
                            class="min-h-hit self-start px-1 text-body font-semibold text-primary-text underline underline-offset-2">{{ __('dash.start_show') }}</button>
                @endif
            </div>
        </section>
    </div>

    {{-- Duyệt bài · Bài của tôi · Nội dung gần đây --}}
    @if ($review !== null || $mine !== null || $recent !== null)
        <div class="grid grid-cols-1 items-start gap-4 @min-[45rem]/tq:grid-cols-2">
            @if ($review !== null)
                <section class="{{ $card }}" aria-labelledby="tq-h-rev" x-data="{ tab: 'queue', keys: ['queue', 'mine', 'sched'], go(d) { const i = (this.keys.indexOf(this.tab) + d + this.keys.length) % this.keys.length; this.tab = this.keys[i]; this.$nextTick(() => document.getElementById('tq-tab-' + this.tab)?.focus()) }, to(i) { this.tab = this.keys[i]; this.$nextTick(() => document.getElementById('tq-tab-' + this.tab)?.focus()) } }">
                    <div class="{{ $ch }}">
                        <h2 id="tq-h-rev" class="{{ $h2 }}">{{ __('dash.rev_title') }}</h2>
                        @if ($rev !== null)
                            <span class="{{ $chip }} {{ $tones['warn'] }}"><x-bs-admin::icon name="ri-time" class="size-[calc(0.875rem+var(--user-text-boost))]" />{{ __('dash.rev_badge', ['n' => $revTabs['queue']['n']]) }}</span>
                        @endif
                    </div>
                    @if ($rev === null)
                        @include('admin::livewire.dashboard.card-error', ['card' => 'review', 'label' => __('dash.rev_title'), 'btn' => $btn])
                    @else
                        <div role="tablist" aria-label="{{ __('dash.rev_tabs_label') }}" class="flex flex-wrap gap-1 border-b border-border px-3">
                            @foreach ($revTabs as $key => $t)
                                <button type="button" role="tab" id="tq-tab-{{ $key }}" aria-controls="tq-rev-{{ $key }}"
                                        :aria-selected="tab === @js($key) ? 'true' : 'false'" :tabindex="tab === @js($key) ? 0 : -1"
                                        aria-selected="{{ $key === 'queue' ? 'true' : 'false' }}" tabindex="{{ $key === 'queue' ? 0 : -1 }}"
                                        x-on:click="tab = @js($key)" x-on:keydown.right.prevent="go(1)" x-on:keydown.left.prevent="go(-1)"
                                        x-on:keydown.home.prevent="to(0)" x-on:keydown.end.prevent="to(keys.length - 1)"
                                        class="-mb-px inline-flex min-h-control items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-2 text-body font-semibold text-text-muted aria-selected:border-primary-text aria-selected:text-primary-text any-pointer-coarse:min-h-hit">
                                    {{ $t['label'] }}<span class="inline-flex min-h-[1.375rem] min-w-[1.375rem] items-center justify-center rounded-full bg-surface-hover px-1.5 text-caption font-bold text-text">{{ $t['n'] }}</span>
                                </button>
                            @endforeach
                        </div>
                        @foreach ($revTabs as $key => $t)
                            <div role="tabpanel" id="tq-rev-{{ $key }}" aria-labelledby="tq-tab-{{ $key }}" @if ($key !== 'queue') hidden @endif x-bind:hidden="tab !== @js($key)">
                                @forelse ($t['rows'] as $row)
                                    <div class="{{ $li }}">
                                        <span class="{{ $tic }} {{ $tones[$key === 'sched' ? 'info' : 'warn'] }}" aria-hidden="true"><x-bs-admin::icon :name="$typeIcon($row)" class="size-[calc(1.0625rem+var(--user-text-boost))]" /></span>
                                        <div class="flex min-w-0 flex-col gap-1">
                                            <div class="{{ $tt }}">{{ $row['title'] }}</div>
                                            <div class="{{ $lm }}">
                                                @if ($key === 'sched')
                                                    <x-bs-admin::status-pill status="scheduled">{{ __('entries.status_scheduled') }}</x-bs-admin::status-pill>
                                                    <span>{{ $meta([$row['section'], __('dash.sched_meta', ['when' => $stamp($row['published_at'])])]) }}</span>
                                                @else
                                                    <x-bs-admin::status-pill status="pending">{{ $statusLabel($row) }}</x-bs-admin::status-pill>
                                                    <span>{{ $meta([$row['section'], $row['author'], $ago($row['updated_at'])]) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($key === 'sched')
                                            <a href="{{ $row['url'] }}" wire:navigate class="{{ $btn }}" aria-label="{{ __($row['edit'] ? 'dash.edit_aria' : 'dash.view_aria', ['title' => $row['title']]) }}">{{ __($row['edit'] ? 'dash.edit' : 'dash.view') }}</a>
                                        @else
                                            <a href="{{ route('admin.entries.review') }}" wire:navigate class="{{ $btn }}" aria-label="{{ __('dash.rev_btn_aria', ['title' => $row['title']]) }}">{{ __('dash.rev_btn') }}</a>
                                        @endif
                                    </div>
                                @empty
                                    <p class="{{ $empty }}">{{ $t['empty'] }}</p>
                                @endforelse
                                @if ($key === 'sched' && $schedMore > 0)
                                    <p class="{{ $empty }}">{{ __('dash.rev_more', ['n' => $num($schedMore)]) }}</p>
                                @endif
                                <div class="{{ $foot }}">
                                    @if ($key === 'sched')
                                        <a href="{{ $postsList(['tab' => 'scheduled']) }}" wire:navigate class="{{ $btn }}"><span>{{ __('dash.rev_foot_sched') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" /></a>
                                    @else
                                        <a href="{{ route('admin.entries.review') }}" wire:navigate class="{{ $btn }}"><span>{{ __('dash.rev_foot_inbox') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" /></a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                </section>
            @endif

            @if ($mine !== null)
                <section class="{{ $card }}" aria-labelledby="tq-h-wr">
                    <div class="{{ $ch }}"><h2 id="tq-h-wr" class="{{ $h2 }}">{{ __('dash.wr_title') }}</h2></div>
                    @if ($wr === null)
                        @include('admin::livewire.dashboard.card-error', ['card' => 'mine', 'label' => __('dash.wr_title'), 'btn' => $btn])
                    @else
                        <div class="flex min-w-0 flex-col gap-4 p-4">
                            @if ($wr['any'])
                                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,8.125rem),1fr))] gap-2.5">
                                    @foreach ($wr['tiles'] as $status => $n)
                                        <a href="{{ $postsList(['tab' => $status, 'author' => [$me->ulid]]) }}" wire:navigate
                                           aria-label="{{ __('dash.wr_tile_aria', ['label' => __('entries.status_'.$status), 'n' => $num($n)]) }}"
                                           class="flex min-h-hit min-w-0 flex-col items-start gap-1.5 rounded-md border border-border bg-surface p-3 text-left transition-colors hover:bg-surface-hover">
                                            <span class="text-title font-bold leading-[1.3] text-text-strong" aria-hidden="true">{{ $num($n) }}</span>
                                            <span class="{{ $chip }} {{ $tones[$tileTone[$status] ?? ''] }}" aria-hidden="true"><x-bs-admin::icon :name="$tileIcon[$status] ?? 'ri-draft'" class="size-[calc(0.875rem+var(--user-text-boost))]" />{{ __('entries.status_'.$status) }}</span>
                                        </a>
                                    @endforeach
                                    @if ($wr['returned'] !== [])
                                        <a href="#tq-h-ret" aria-label="{{ __('dash.wr_tile_aria', ['label' => __('dash.wr_returned'), 'n' => $num(count($wr['returned']))]) }}"
                                           class="flex min-h-hit min-w-0 flex-col items-start gap-1.5 rounded-md border border-border bg-surface p-3 text-left transition-colors hover:bg-surface-hover">
                                            <span class="text-title font-bold leading-[1.3] text-text-strong" aria-hidden="true">{{ $num(count($wr['returned'])) }}</span>
                                            <span class="{{ $chip }} {{ $tones['danger'] }}" aria-hidden="true"><x-bs-admin::icon name="ri-arrow-go-back" class="size-[calc(0.875rem+var(--user-text-boost))]" />{{ __('dash.wr_returned') }}</span>
                                        </a>
                                    @endif
                                </div>
                            @endif
                            @if ($wr['returned'] !== [])
                                <div class="flex flex-col gap-2">
                                    <h3 id="tq-h-ret" class="text-body font-semibold text-text-strong">{{ __('dash.wr_returned_title') }}</h3>
                                    <div class="rounded-md border border-border">
                                        @foreach ($wr['returned'] as $r)
                                            <div class="{{ $li }}">
                                                <span class="{{ $tic }} {{ $tones['danger'] }}" aria-hidden="true"><x-bs-admin::icon name="ri-arrow-go-back" class="size-[calc(1.0625rem+var(--user-text-boost))]" /></span>
                                                <div class="flex min-w-0 flex-col gap-1">
                                                    <div class="{{ $tt }}">{{ $r['title'] }}</div>
                                                    @if ($r['reason'] !== '')
                                                        <div class="text-small leading-normal text-text [overflow-wrap:anywhere]">{{ __('dash.wr_reason', ['reason' => $r['reason']]) }}</div>
                                                    @endif
                                                    <div class="{{ $lm }}"><span>{{ $r['by'] !== '' ? __('dash.wr_by', ['name' => $r['by'], 'when' => $ago($r['at'])]) : __('dash.wr_by_gone', ['when' => $ago($r['at'])]) }}</span></div>
                                                </div>
                                                <a href="{{ $r['url'] }}" wire:navigate class="{{ $btn }}" aria-label="{{ __('dash.wr_fix_aria', ['title' => $r['title']]) }}">{{ __('dash.wr_fix') }}</a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @unless ($wr['any'])
                                <div class="flex flex-col items-start gap-2 {{ $ts }}">
                                    <span>{{ __('dash.wr_empty') }}</span>
                                    <a href="{{ $postsList() }}" wire:navigate class="{{ $ghost }}"><span>{{ __('dash.open_list') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" /></a>
                                </div>
                            @endunless
                        </div>
                        <div class="{{ $foot }}">
                            <a href="{{ $postsList(['author' => [$me->ulid]]) }}" wire:navigate class="{{ $ghost }}"><span>{{ __('dash.wr_all') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" /></a>
                        </div>
                    @endif
                </section>
            @endif

            @if ($recent !== null)
                <section class="{{ $card }}" aria-labelledby="tq-h-rec">
                    <div class="{{ $ch }}"><h2 id="tq-h-rec" class="{{ $h2 }}">{{ __($editor ? 'dash.rec_title_edit' : 'dash.rec_title_view') }}</h2></div>
                    @if (! $recent['ok'])
                        @include('admin::livewire.dashboard.card-error', ['card' => 'recent', 'label' => __($editor ? 'dash.rec_title_edit' : 'dash.rec_title_view'), 'btn' => $btn])
                    @else
                        <div>
                            @forelse ($recent['data'] as $row)
                                <div class="{{ $li }}">
                                    <span class="{{ $tic }} {{ $tones[''] }}" aria-hidden="true"><x-bs-admin::icon :name="$typeIcon($row)" class="size-[calc(1.0625rem+var(--user-text-boost))]" /></span>
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <div class="{{ $tt }}">{{ $row['title'] }}</div>
                                        <div class="{{ $lm }}">
                                            <x-bs-admin::status-pill :status="$row['status']">{{ __('entries.status_'.$row['status']) }}</x-bs-admin::status-pill>
                                            <span>{{ $meta([$row['section'], $row['author'], $stamp($row['updated_at'])]) }}</span>
                                        </div>
                                    </div>
                                    <a href="{{ $row['url'] }}" wire:navigate class="{{ $btn }}" aria-label="{{ __($row['edit'] ? 'dash.edit_aria' : 'dash.view_aria', ['title' => $row['title']]) }}">{{ __($row['edit'] ? 'dash.edit' : 'dash.view') }}</a>
                                </div>
                            @empty
                                <div class="{{ $empty }}">
                                    <span>{{ __($canCreate ? 'dash.rec_empty_create' : 'dash.rec_empty_view') }}</span>
                                    @if ($canCreate)
                                        <a href="{{ $postsList() }}" wire:navigate class="{{ $ghost }}"><span>{{ __('dash.open_list') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" /></a>
                                    @endif
                                </div>
                            @endforelse
                        </div>
                        <div class="{{ $foot }}">
                            <a href="{{ $postsList() }}" wire:navigate class="{{ $ghost }}"><span>{{ __('dash.rec_all') }}</span><x-bs-admin::icon name="ri-arrow-right" class="size-[calc(1rem+var(--user-text-boost))]" /></a>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    @endif

    <x-bs-admin::toast :message="$toastMsg" :seq="$toastSeq" dismiss="closeToast" :undo="$toastUndo ? 'showStart' : null" />
</div>
