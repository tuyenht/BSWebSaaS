@php
    use App\Modules\Admin\Support\DeviceLabel;
    use App\Modules\Admin\Support\Leads\LeadListQuery;
    use App\Modules\Forms\Support\LeadAnswers;
    use App\Modules\Shared\Support\Cast;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $locale = app()->getLocale();
    $over = __('catalog.count_over');
    $num = fn (int $n): string => Number::format($n, locale: $locale);
    $countOf = fn (int $n): int|string => $n > $cap ? $over : $n;
    $fmt = fn (int|string $n): string => is_string($n) ? $n : $num($n);

    // The status of a lead: its words, icon and colours (the pill of a row and of the lead being read).
    $stIcons = ['new' => 'ri-mail-unread', 'read' => 'ri-mail-open', 'archived' => 'ri-check-double', 'spam' => 'ri-spam-2'];
    $stTone = [
        'new' => 'bg-info-soft text-info-text',
        'read' => 'bg-surface-hover text-text',
        'archived' => 'bg-success-soft text-success-text',
        'spam' => 'bg-danger-soft text-danger-text',
    ];
    $pill = 'inline-flex min-h-[1.375rem] items-center gap-1 whitespace-nowrap rounded-full px-2 text-caption font-semibold leading-snug';

    // Status groups: every lead but spam, then each status (spam last).
    $groups = [];
    foreach (LeadListQuery::TABS as $key) {
        $groups[] = ['key' => $key, 'label' => __('leads.tab_'.$key), 'count' => $countOf($counts[$key] ?? 0)];
    }
    $groupLabel = $tab === 'all' ? null : __('leads.tab_'.$tab);

    // A form's name in the member's language (its first name otherwise).
    $formName = function ($form) use ($locale): string {
        $names = Cast::arr($form?->name);
        $name = Cast::string($names[$locale] ?? $names['vi'] ?? (array_values($names)[0] ?? ''));

        return $name !== '' ? $name : __('leads.form_untitled');
    };
    $formsById = $forms->keyBy('id');

    // The filters: the site's own forms (disabled ones too), then the time the lead was sent.
    $formOptions = [];
    foreach ($forms as $form) {
        $formOptions[] = ['value' => $form->ulid, 'label' => $formName($form), 'count' => $formCounts === null ? null : ($formCounts[$form->id] ?? 0)];
    }
    $day = fn (string $date): string => $date === '' ? '…' : implode('/', array_reverse(explode('-', $date)));
    $whenLabels = ['' => __('entries.when_all'), 'today' => __('entries.when_today'), '7' => __('entries.when_days', ['n' => 7]), '30' => __('entries.when_days', ['n' => 30])];
    $whenText = $when === LeadListQuery::RANGE ? __('entries.when_range', ['from' => $day($from), 'to' => $day($to)]) : ($whenLabels[$when] ?? '');
    $sections = [
        ['key' => 'status', 'label' => __('leads.f_status'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count']], $groups)],
    ];
    if ($formOptions !== []) {
        $sections[] = ['key' => 'form', 'label' => __('leads.f_form'), 'icon' => 'ri-survey', 'model' => 'formF', 'multiple' => true, 'value' => $formF,
            'reset' => 'resetFilterGroup('.Js::from('form').')', 'options' => $formOptions];
    }
    $sections[] = ['key' => 'when', 'label' => __('leads.f_when'), 'icon' => 'ri-calendar', 'model' => 'when', 'value' => $when, 'default' => '', 'note' => __('leads.when_note'),
        'reset' => 'resetFilterGroup('.Js::from('when').')',
        'options' => array_map(fn (string $v): array => ['value' => $v, 'label' => $whenLabels[$v], 'count' => $countOf($whenCounts[$v] ?? 0)], LeadListQuery::WHEN),
        'range' => ['from' => 'from', 'to' => 'to', 'from_label' => __('entries.when_from'), 'to_label' => __('entries.when_to')]]
        + ($when === LeadListQuery::RANGE ? ['on' => true, 'summary' => $whenText, 'suffix' => ': '.$whenText] : []);

    // The active-filter line: "Group · Tìm thấy N trong M liên hệ · Bỏ lọc", a chip per form and for the time.
    $matchN = $counts[$tab] ?? 0;
    $narrowed = $filterCount > 0 || trim($search) !== '';
    $filtering = $tab !== 'all' || $narrowed;
    $chips = [];
    foreach ($forms->whereIn('ulid', $formF) as $form) {
        $chips[] = ['label' => $formName($form), 'icon' => 'ri-survey', 'remove' => 'removeForm('.Js::from($form->ulid).')',
            'aria' => __('leads.chip_form_aria', ['name' => $formName($form)])];
    }
    if ($when !== '') {
        $chips[] = ['label' => $whenText, 'icon' => 'ri-calendar', 'remove' => 'resetFilterGroup('.Js::from('when').')', 'aria' => __('leads.chip_when_aria', ['when' => $whenText])];
    }

    // Times in the site's own zone: "6 phút trước" today, the day and hour before that.
    $nowAt = $siteTime->now();
    $ago = function (?\DateTimeInterface $at) use ($siteTime, $nowAt, $locale): string {
        $t = $siteTime->forDisplay($at);
        if ($t === null) {
            return '';
        }

        return match (true) {
            $t->greaterThan($nowAt->subDay()) => $t->locale($locale)->diffForHumans($nowAt),
            $t->year === $nowAt->year => $t->format('d/m H:i'),
            default => $t->format('d/m/Y'),
        };
    };
    $at = fn (?\DateTimeInterface $at): string => $siteTime->forDisplay($at)?->format('d/m/Y H:i') ?? '';
    // a page address is linked only when it is a web address (it comes from the visitor's browser)
    $page = function (?string $url): array {
        $url = (string) $url;
        $web = preg_match('#^https?://#i', $url) === 1;
        $path = $web ? (parse_url($url, PHP_URL_PATH) ?: '/') : $url;

        return ['href' => $web ? $url : null, 'path' => (string) $path];
    };
    $digits = fn (?string $phone): string => preg_replace('/\D+/', '', (string) $phone) ?? '';
    // the row's excerpt: the message, else the form's first long answer
    $excerpt = function ($lead) use ($formsById): string {
        $data = Cast::arr($lead->data);
        $text = Cast::string($data['message'] ?? null);
        if ($text === '') {
            foreach ($formsById->get($lead->form_id)?->fields ?? [] as $field) {
                if ($field->type === 'textarea' && is_string($data[$field->key] ?? null)) {
                    $text = $data[$field->key];
                    break;
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    };

    // Ticking (a member who manages leads): the boxes, "Chọn tất cả" and the bar's three actions.
    $rowUlids = $rows->pluck('ulid')->all();
    $pickedN = count($picked);
    $allOn = $rowUlids !== [] && array_diff($rowUlids, array_keys($picked)) === [];
    $allState = $allOn ? true : (array_intersect($rowUlids, array_keys($picked)) !== [] ? 'mixed' : false);
    $selectCall = 'toggleSelect('.Js::from($rowUlids).')';
    $bulk = [];
    $bulkSkip = null;
    if ($canManage && $pickedN > 0) {
        $can = fn (array $from): int => count(array_filter($picked, fn (string $st): bool => in_array($st, $from, true)));
        foreach ([
            ['bulkRead', 'ri-mail-open', 'leads.bulk_read', $can(['new', 'archived'])],
            ['bulkDone', 'ri-check-double', 'leads.bulk_archived', $can(['new', 'read'])],
            ['bulkSpam', 'ri-spam-2', 'leads.bulk_spam', $can(['new', 'read', 'archived'])],
        ] as [$call, $icon, $key, $ok]) {
            $skip = $pickedN - $ok;
            $bulk[] = ['call' => $call, 'icon' => $icon, 'label' => __($key, ['count' => $num($ok)]), 'ok' => $ok,
                'title' => $ok === 0 ? __('leads.bulk_none') : ($skip > 0 ? __('leads.bulk_skip_title', ['count' => $num($skip)]) : '')];
            if ($skip > 0) {
                $bulkSkip = __('leads.bulk_skip_note');
            }
        }
    }

    // The lead being read.
    $d = null;
    if ($open !== null) {
        $answers = LeadAnswers::for($openForm, Cast::arr($open->data), $locale, withEmpty: true);
        $kv = array_values(array_filter($answers, fn (array $a): bool => ! $a['ticked']));
        // the contact columns the form had no row for still say what the visitor left
        foreach (['phone', 'email'] as $key) {
            if (! collect($kv)->contains('key', $key) && Cast::string($open->{$key}) !== '') {
                $kv[] = ['key' => $key, 'label' => __('forms.lead_field_'.$key), 'value' => Cast::string($open->{$key}), 'ticked' => false, 'wide' => false];
            }
        }
        $phone = Cast::string($open->phone);
        $email = Cast::string($open->email);
        $tel = $digits($phone);
        $agent = Cast::string($open->user_agent);
        $d = [
            'name' => Cast::string($open->name) !== '' ? Cast::string($open->name) : ($phone !== '' ? $phone : ($email !== '' ? $email : __('leads.no_name'))),
            'status' => $open->status,
            'form' => $formName($openForm),
            'ago' => $ago($open->created_at),
            'when' => $at($open->created_at),
            'flagged' => (bool) $open->is_spam,
            'phone' => $phone,
            'email' => $email,
            'tel' => $tel !== '' ? 'tel:'.$tel : null,
            'zalo' => $tel !== '' ? 'https://zalo.me/'.$tel : null,
            'mailto' => $email !== '' ? 'mailto:'.$email.'?subject='.rawurlencode(__('leads.mail_subject', ['form' => $formName($openForm)])) : null,
            'kv' => $kv,
            'consents' => array_values(array_filter($answers, fn (array $a): bool => $a['ticked'])),
            'page' => $page($open->source_url),
            'device' => $agent === '' ? '—' : (DeviceLabel::of($agent) ?? __('admin.audit.device_other')),
            'ip' => Cast::string($open->ip_address) !== '' ? Cast::string($open->ip_address) : '—',
        ];
    }
    $segs = [['new', 'ri-mail-unread'], ['read', 'ri-mail-open'], ['archived', 'ri-check-double']];
    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card';
    $h2 = 'm-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]';
@endphp
<div class="leads group/kl @container/kl flex flex-col gap-4 max-[599.98px]:gap-2" data-open="{{ $opened ? '1' : '0' }}">

    <header class="flex flex-col gap-1">
        <h1 class="m-0 min-w-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('leads.title') }}</h1>
        <p class="m-0 text-body text-text-muted [overflow-wrap:anywhere] [text-wrap:pretty]">{{ __('leads.desc') }}</p>
    </header>

    @if ($failed)
        <x-bs-admin::card flush role="alert" data-state="error" class="min-w-0">
            <div class="flex max-w-160 flex-col items-start gap-3 px-6 py-8">
                <x-bs-admin::icon name="ri-wifi-off" class="size-[calc(2rem+var(--user-text-boost))] text-primary-text" />
                <h2 class="{{ $h2 }}">{{ __('leads.error_title') }}</h2>
                <p class="m-0 text-body text-text [text-wrap:pretty]">{{ __('leads.error_text') }}</p>
                <x-bs-admin::button color="primary" wire:click="$refresh">
                    <x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('leads.error_retry') }}
                </x-bs-admin::button>
            </div>
        </x-bs-admin::card>
    @elseif ($empty)
        <x-bs-admin::card flush role="status" data-state="empty" class="min-w-0">
            <div class="flex max-w-160 flex-col items-start gap-3 px-6 py-8">
                <x-bs-admin::icon name="ri-mail-open" class="size-[calc(2rem+var(--user-text-boost))] text-primary-text" />
                <h2 class="{{ $h2 }}">{{ __('leads.empty_title') }}</h2>
                <p class="m-0 text-body text-text [text-wrap:pretty]">{{ __('leads.empty_text') }}</p>
                <ol class="m-0 flex list-decimal flex-col gap-1.5 ps-5 text-body text-text">
                    <li>{{ __('leads.empty_step_1') }}</li>
                    <li>{{ __('leads.empty_step_2') }}</li>
                </ol>
            </div>
        </x-bs-admin::card>
    @else
    <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-4 @min-[58.75rem]/kl:grid-cols-[minmax(21.25rem,25rem)_minmax(0,1fr)]">

        {{-- The list. Below 940px (the screen's own width) it gives way to the lead once one is opened. --}}
        <section aria-label="{{ __('leads.list_label') }}" class="{{ $card }} @container/lt flex flex-col @max-[58.74rem]/kl:group-data-[open=1]/kl:hidden">
            <x-bs-admin::group-row class="px-2" :groups="$groups" :active="$tab" model="tab" :label="__('leads.groups_label')" />

            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-3">
                <div class="min-w-0 flex-[1_1_13.75rem]">
                    <x-bs-admin::search id="leads-search" :label="__('leads.search_label')" :placeholder="__('leads.search_hint')"
                        :clear-label="__('leads.search_clear')" clear-action="$set('search', '')" :clear-on="trim($search) !== ''" wire:model.live.debounce.300ms="search" />
                </div>
                @if ($canManage && $rowUlids !== [])
                    <div class="min-w-0">
                        <x-bs-admin::list.check :checked="$allState" :label="__('leads.select_all')" :aria-label="__('leads.select_all_aria', ['n' => $num(count($rowUlids))])" visible wire:click="{{ $selectCall }}" />
                    </div>
                @endif
                <x-bs-admin::filter-panel class="ms-auto" :sections="$sections" :active="$filterCount" :active-narrow="$filterCount + ($tab !== 'all' ? 1 : 0)"
                    clear="clearAllFilters" :clear-closes="true" :results="__('leads.show_n', ['n' => $fmt($countOf($matchN))])" :label="__('admin.list.filter')" />
            </div>

            <x-bs-admin::filter-status class="-mt-1 mb-2 px-3" :group="$groupLabel"
                :summary="$narrowed && $total !== null ? __('leads.match_of', ['n' => $fmt($countOf($matchN)), 'm' => $fmt($countOf($total))]) : null"
                :chips="$chips" :clear="$narrowed ? 'clearAllFilters' : null" />

            @if ($pickedN > 0 && $bulk !== [])
                <x-bs-admin::list.bulk :label="__('leads.bulk_region')" :status="__('leads.bulk_selected', ['count' => $num($pickedN)])" :clear-label="__('leads.bulk_clear')" :skip="$bulkSkip">
                    @foreach ($bulk as $action)
                        <x-bs-admin::button size="sm" variant="soft" color="primary" :title="$action['title']" aria-disabled="{{ $action['ok'] > 0 ? 'false' : 'true' }}"
                            class="aria-disabled:opacity-60" x-on:click="{{ $action['ok'] > 0 ? '$wire.'.$action['call'].'()' : '' }}">
                            <x-bs-admin::icon :name="$action['icon']" class="size-4" /> {{ $action['label'] }}
                        </x-bs-admin::button>
                    @endforeach
                </x-bs-admin::list.bulk>
            @endif

            <div wire:loading.delay.class="opacity-60" class="flex min-w-0 flex-col">
                @forelse ($rows as $lead)
                    @php
                        $isOn = $open !== null && $open->ulid === $lead->ulid;
                        $name = Cast::string($lead->name) !== '' ? Cast::string($lead->name) : __('leads.no_name');
                        $contact = array_values(array_filter([Cast::string($lead->phone), Cast::string($lead->email)]));
                        $rowPage = $page($lead->source_url)['path'];
                        $ticked = isset($picked[$lead->ulid]);
                    @endphp
                    <div wire:key="lead-{{ $lead->ulid }}" data-lead="{{ $lead->ulid }}" @class([
                        'relative flex items-stretch border-t border-border',
                        'bg-primary-soft before:absolute before:inset-y-0 before:start-0 before:w-[3px] before:bg-primary-text' => $isOn,
                    ])>
                        @if ($canManage)
                            <div class="flex shrink-0 items-start justify-center ps-1 pt-1">
                                <x-bs-admin::list.check :checked="$ticked" :label="__('leads.select_named', ['name' => $name])" wire:click="toggleSelect({{ Js::from([$lead->ulid]) }})" />
                            </div>
                        @endif
                        <button type="button" wire:click="openLead({{ Js::from($lead->ulid) }})" @if ($isOn) aria-current="true" @endif
                            @class(['focus-ring flex min-w-0 flex-1 flex-col gap-[3px] border-0 bg-transparent py-2.5 pe-3 text-start text-body text-text hover:bg-surface-hover', 'ps-1' => $canManage, 'ps-4' => ! $canManage])>
                            <span class="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                <span @class(['min-w-0 flex-[1_1_9em] text-text-strong [overflow-wrap:break-word]', 'font-bold' => $lead->status === 'new', 'font-semibold' => $lead->status !== 'new'])>{{ $name }}</span>
                                <span class="shrink-0 whitespace-nowrap text-caption text-text-muted">{{ $ago($lead->created_at) }}</span>
                            </span>
                            <span class="text-small text-text [overflow-wrap:anywhere]">{{ $contact !== [] ? implode(' · ', $contact) : __('leads.no_contact') }}</span>
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-text-muted">
                                <span class="{{ $pill }} {{ $stTone[$lead->status] ?? $stTone['read'] }}"><x-bs-admin::icon :name="$stIcons[$lead->status] ?? 'ri-mail-open'" class="size-[calc(0.8125rem+var(--user-text-boost))]" />{{ __('leads.st_'.$lead->status) }}</span>
                                <span class="[overflow-wrap:anywhere]">{{ $formName($formsById->get($lead->form_id)) }}</span>
                                @if ($rowPage !== '')
                                    <span aria-hidden="true">·</span><span class="[overflow-wrap:anywhere]">{{ $rowPage }}</span>
                                @endif
                                @if ($lead->is_spam && $lead->status !== 'spam')
                                    <span class="inline-flex items-center gap-1 text-danger-text"><x-bs-admin::icon name="ri-shield-flash" class="size-[calc(0.8125rem+var(--user-text-boost))]" />{{ __('leads.flagged') }}</span>
                                @endif
                            </span>
                            @if (($text = $excerpt($lead)) !== '')
                                <span class="line-clamp-2 text-small text-text-muted [overflow-wrap:anywhere]"><span>{{ $text }}</span></span>
                            @endif
                        </button>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center gap-2 border-t border-border px-4 py-12 text-center text-body text-text-muted">
                        <x-bs-admin::icon name="ri-search-eye" class="size-[calc(2rem+var(--user-text-boost))] text-text-faint" />
                        <span>{{ $narrowed ? __('leads.no_match') : __('leads.none_here') }}</span>
                        @if ($narrowed)
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="clearAllFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                        @endif
                    </div>
                @endforelse

                @if ($rows->count() < $matchN && $rows->count() < \App\Modules\Admin\Livewire\Forms\Inbox::MAX_ROWS)
                    <div class="flex flex-wrap items-center justify-center gap-2 border-t border-border p-3 text-small text-text-muted">
                        <span>{{ __('leads.shown_of', ['n' => $num($rows->count()), 'm' => $fmt($countOf($matchN))]) }}</span>
                        <x-bs-admin::button variant="outline" color="secondary" wire:click="loadMore">
                            <x-bs-admin::icon name="ri-arrow-down" class="size-4" /> {{ __('leads.load_more') }}
                        </x-bs-admin::button>
                    </div>
                @endif
            </div>
        </section>

        {{-- The lead being read: a column of its own from 940px (it scrolls inside), the whole screen below once opened. --}}
        <section aria-label="{{ __('leads.detail_label') }}" class="{{ $card }} flex flex-col @max-[58.74rem]/kl:group-data-[open=0]/kl:hidden @min-[58.75rem]/kl:sticky @min-[58.75rem]/kl:top-[calc(var(--topbar-height)+1rem)] @min-[58.75rem]/kl:max-h-[calc(100dvh-var(--topbar-height)-2rem)]">
            @if ($d !== null)
                <div class="px-2 pt-2 @min-[58.75rem]/kl:hidden">
                    <x-bs-admin::button variant="ghost" color="secondary" wire:click="closeLead" x-init="$el.focus({ preventScroll: true })">
                        <x-bs-admin::icon name="ri-arrow-left" class="size-4" /> {{ __('leads.back') }}
                    </x-bs-admin::button>
                </div>
                <div class="flex flex-col gap-2 border-b border-border p-4">
                    <h2 class="{{ $h2 }} scroll-mt-2">{{ $d['name'] }}</h2>
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-small text-text-muted">
                        <span class="{{ $pill }} {{ $stTone[$d['status']] ?? $stTone['read'] }}"><x-bs-admin::icon :name="$stIcons[$d['status']] ?? 'ri-mail-open'" class="size-[calc(0.8125rem+var(--user-text-boost))]" />{{ __('leads.st_'.$d['status']) }}</span>
                        <span>{{ __('leads.sent_via', ['form' => $d['form']]) }}</span>
                        <span>{{ $d['ago'] }}</span>
                    </div>
                    @if ($d['flagged'] && $d['status'] === 'spam')
                        <p class="m-0 flex items-start gap-1.5 text-small font-semibold text-danger-text"><x-bs-admin::icon name="ri-shield-flash" class="mt-0.5 size-4 shrink-0" />{{ __('leads.flagged') }}</p>
                    @elseif ($d['flagged'])
                        <p class="m-0 flex items-start gap-1.5 text-small text-text"><x-bs-admin::icon name="ri-shield-flash" class="mt-0.5 size-4 shrink-0 text-danger-text" />{{ __('leads.flagged_off') }}</p>
                    @endif
                    <div class="flex items-start gap-2 @max-[58.74rem]/kl:hidden">
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                            @if ($d['tel'])
                                <x-bs-admin::button color="primary" :href="$d['tel']" :navigate="false"><x-bs-admin::icon name="ri-phone" class="size-4" /> {{ __('leads.call', ['phone' => $d['phone']]) }}</x-bs-admin::button>
                                <x-bs-admin::button variant="outline" color="secondary" :href="$d['zalo']" :navigate="false" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-chat-3" class="size-4" /> {{ __('leads.zalo') }}</x-bs-admin::button>
                            @endif
                            @if ($d['mailto'])
                                <x-bs-admin::button variant="outline" color="secondary" :href="$d['mailto']" :navigate="false"><x-bs-admin::icon name="ri-mail-send" class="size-4" /> {{ __('leads.email') }}</x-bs-admin::button>
                            @endif
                        </div>
                        @if ($canManage)
                            @include('admin::livewire.forms.partials.lead-menu', ['status' => $d['status'], 'ulid' => $open->ulid, 'name' => $d['name']])
                        @endif
                    </div>
                    @if (! $d['tel'] && ! $d['mailto'])
                        <span class="text-small text-text-muted">{{ __('leads.no_contact_left') }}</span>
                    @endif
                </div>

                <div class="flex min-h-0 min-w-0 flex-col gap-4 p-4 @min-[58.75rem]/kl:flex-1 @min-[58.75rem]/kl:overflow-auto">
                    @if ($d['status'] === 'spam')
                        <x-bs-admin::alert color="danger" class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span class="min-w-0 flex-[1_1_15rem] text-body">{{ __('leads.spam_note') }}</span>
                            @if ($canManage)
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="setStatus({{ Js::from($open->ulid) }}, 'read')">
                                    <x-bs-admin::icon name="ri-mail-check" class="size-4" /> {{ __('leads.not_spam') }}
                                </x-bs-admin::button>
                            @endif
                        </x-bs-admin::alert>
                    @elseif ($canManage)
                        <div class="flex flex-col gap-1.5">
                            <span class="text-small font-medium text-text-strong" id="leads-st-label">{{ __('leads.status_label') }}</span>
                            <div role="radiogroup" aria-labelledby="leads-st-label" class="flex min-w-0 flex-wrap gap-2 self-start">
                                @foreach ($segs as [$key, $icon])
                                    <x-bs-admin::button variant="outline" color="secondary" role="radio" aria-checked="{{ $d['status'] === $key ? 'true' : 'false' }}"
                                        class="min-h-hit aria-checked:border-primary-text aria-checked:bg-primary-soft aria-checked:font-semibold aria-checked:text-primary-text"
                                        wire:click="setStatus({{ Js::from($open->ulid) }}, {{ Js::from($key) }})">
                                        <x-bs-admin::icon :name="$icon" class="size-4" /> {{ __('leads.st_'.$key) }}
                                    </x-bs-admin::button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <dl class="m-0 grid grid-cols-[minmax(0,1fr)]">
                        @foreach ($d['kv'] as $row)
                            <div class="grid grid-cols-[minmax(0,1fr)] gap-x-3 gap-y-0.5 border-t border-border py-2 text-body first:border-t-0 @min-[32.5rem]/kl:grid-cols-[9.375rem_minmax(0,1fr)]">
                                <dt class="text-text-muted">{{ $row['label'] }}</dt>
                                @if ($row['value'] !== '')
                                    <dd class="m-0 whitespace-pre-wrap text-text-strong [overflow-wrap:anywhere]">{{ $row['value'] }}</dd>
                                @else
                                    <dd class="m-0 italic text-text-muted">{{ __('leads.not_filled') }}</dd>
                                @endif
                            </div>
                        @endforeach
                        @if ($d['page']['path'] !== '')
                            <div class="grid grid-cols-[minmax(0,1fr)] gap-x-3 gap-y-0.5 border-t border-border py-2 text-body @min-[32.5rem]/kl:grid-cols-[9.375rem_minmax(0,1fr)]">
                                <dt class="text-text-muted">{{ __('leads.page') }}</dt>
                                <dd class="m-0 text-text-strong [overflow-wrap:anywhere]">
                                    @if ($d['page']['href'])
                                        <a href="{{ $d['page']['href'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-hit items-center gap-1 text-primary-text hover:underline">{{ $d['page']['path'] }} <x-bs-admin::icon name="ri-external-link" class="size-4" /><span class="sr-only">{{ __('leads.new_tab') }}</span></a>
                                    @else
                                        {{ $d['page']['path'] }}
                                    @endif
                                </dd>
                            </div>
                        @endif
                        <div class="grid grid-cols-[minmax(0,1fr)] gap-x-3 gap-y-0.5 border-t border-border py-2 text-body @min-[32.5rem]/kl:grid-cols-[9.375rem_minmax(0,1fr)]">
                            <dt class="text-text-muted">{{ __('leads.sent_at') }}</dt>
                            <dd class="m-0 text-text-strong">{{ $d['when'] }}</dd>
                        </div>
                    </dl>

                    @foreach ($d['consents'] as $consent)
                        <div role="note" aria-label="{{ __('leads.consent_label') }}" class="flex items-start gap-2.5 rounded-md border border-border-strong bg-surface-muted p-3">
                            <x-bs-admin::icon name="ri-shield-check" class="mt-0.5 size-[calc(1.125rem+var(--user-text-boost))] shrink-0 text-success-text" />
                            <div class="min-w-0">
                                <p class="m-0 text-body text-text-strong [overflow-wrap:anywhere]">{{ __('leads.consent_line', ['sentence' => $consent['value'], 'when' => $d['when']]) }}</p>
                                <small class="mt-1 block text-caption text-text-muted">{{ __('leads.consent_note') }}</small>
                            </div>
                        </div>
                    @endforeach

                    <details class="text-body">
                        <summary class="flex min-h-hit cursor-pointer items-center gap-1.5 font-medium text-primary-text"><x-bs-admin::icon name="ri-information" class="size-4" />{{ __('leads.tech') }}</summary>
                        <dl class="m-0 grid grid-cols-[minmax(0,1fr)]">
                            <div class="grid grid-cols-[minmax(0,1fr)] gap-x-3 gap-y-0.5 py-2 @min-[32.5rem]/kl:grid-cols-[9.375rem_minmax(0,1fr)]">
                                <dt class="text-text-muted">{{ __('leads.device') }}</dt>
                                <dd class="m-0 text-text-strong">{{ $d['device'] }}</dd>
                            </div>
                            <div class="grid grid-cols-[minmax(0,1fr)] gap-x-3 gap-y-0.5 border-t border-border py-2 @min-[32.5rem]/kl:grid-cols-[9.375rem_minmax(0,1fr)]">
                                <dt class="text-text-muted">{{ __('leads.ip') }}</dt>
                                <dd class="m-0 text-text-strong [overflow-wrap:anywhere]">{{ $d['ip'] }}<br><span class="text-small text-text-muted">{{ __('leads.ip_note') }}</span></dd>
                            </div>
                        </dl>
                    </details>
                </div>
            @else
                <div class="flex flex-col items-center justify-center gap-2 px-4 py-12 text-center text-body text-text-muted">
                    <x-bs-admin::icon name="ri-mail" class="size-[calc(2rem+var(--user-text-boost))] text-text-faint" />
                    <span>{{ __('leads.pick_one') }}</span>
                </div>
            @endif
        </section>
    </div>

    {{-- Below 940px, the lead's call / Zalo / email buttons stay at the bottom of the screen while it is read. --}}
    @if ($d !== null && $opened)
        <div data-sticky-savebar class="sticky bottom-0 z-5 flex flex-wrap gap-2 rounded-b-md border-t border-border bg-surface px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] @min-[58.75rem]/kl:hidden">
            @if ($d['tel'])
                <x-bs-admin::button color="primary" class="min-h-12 min-w-[min(100%,max-content)] flex-1" :href="$d['tel']" :navigate="false"><x-bs-admin::icon name="ri-phone" class="size-4" /> {{ __('leads.call', ['phone' => $d['phone']]) }}</x-bs-admin::button>
                <x-bs-admin::button variant="outline" color="secondary" class="min-h-12" :href="$d['zalo']" :navigate="false" target="_blank" rel="noopener" :aria-label="__('leads.zalo_aria')"><x-bs-admin::icon name="ri-chat-3" class="size-4" /> {{ __('leads.zalo_short') }}</x-bs-admin::button>
                @if ($d['mailto'])
                    <x-bs-admin::button variant="outline" color="secondary" class="min-h-12" :href="$d['mailto']" :navigate="false" :aria-label="__('leads.email_aria')"><x-bs-admin::icon name="ri-mail-send" class="size-4" /> {{ __('leads.email_short') }}</x-bs-admin::button>
                @endif
            @elseif ($d['mailto'])
                <x-bs-admin::button color="primary" class="min-h-12 min-w-[min(100%,max-content)] flex-1" :href="$d['mailto']" :navigate="false"><x-bs-admin::icon name="ri-mail-send" class="size-4" /> {{ __('leads.email_big') }}</x-bs-admin::button>
            @endif
            @if ($canManage)
                @include('admin::livewire.forms.partials.lead-menu', ['status' => $d['status'], 'ulid' => $open->ulid, 'name' => $d['name']])
            @endif
        </div>
    @endif
    @endif

    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" :undo="$undoOp !== null ? 'undoLast' : null" :raised="$pickedN > 0 && $bulk !== []" />
</div>
