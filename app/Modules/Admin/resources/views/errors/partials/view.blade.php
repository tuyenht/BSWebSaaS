{{--
    One error state (design board 25, the .te-v block): icon, the status number where it helps,
    a heading, one plain line, then exactly one main action (+ a quiet second one), the reason a
    button is off, the fault's reference and the support channels. Every sentence comes from
    AdminErrorPages — fixed words in the member's language, never an exception's text.
--}}
@php
    /** @var array<string, mixed> $page */
    $primary = $page['primary'];
    $whyId = $page['why'] !== null ? 'te-why-'.$page['key'] : null;
    $codeSpell = $page['code'] !== null ? __('errors.code_spell', ['letters' => implode(' ', str_split(str_replace('-', '', $page['code'])))]) : null;
@endphp
<div class="te-v" data-te-page="{{ $page['key'] }}"
     @if ($page['retryAfter'] !== null) data-te-retry="{{ $page['retryAfter'] }}"
         data-te-wait="{{ __('errors.rate.wait', ['seconds' => '__N__']) }}" data-te-done="{{ __('errors.rate.done') }}"
         data-te-btn-wait="{{ __('errors.rate.btn_wait', ['seconds' => '__N__']) }}" data-te-btn-ready="{{ __('errors.rate.btn_ready') }}"
         data-te-live-done="{{ __('errors.rate.live_done') }}" @endif>
    <div class="te-ic" @if ($page['tone'] !== '') data-tone="{{ $page['tone'] }}" @endif aria-hidden="true">
        <x-bs-admin::icon :name="$page['icon']" class="size-7" />
    </div>
    @if ($page['num'] !== null)
        <div class="te-num" aria-hidden="true">{{ $page['num'] }}</div>
    @endif
    <h1 class="te-t">{{ $page['title'] }}</h1>
    <p class="te-l" data-te-line>{{ $page['line'] }}</p>

    @if ($page['groups'] !== [])
        <div class="te-grp">
            <span>{{ __('errors.groups_can') }}</span>
            @foreach ($page['groups'] as $group)
                <span class="te-chip"><x-bs-admin::icon name="ri-shield-user" class="size-4" />{{ $group }}</span>
            @endforeach
        </div>
    @endif

    @if ($primary !== null || $page['secondary'] !== [])
        <div class="te-acts">
            @if ($primary !== null)
                @if ($primary['href'] !== null)
                    <x-bs-admin::button :href="$primary['href']" :navigate="false">
                        <x-bs-admin::icon :name="$primary['icon']" class="size-4" /><span>{{ $primary['label'] }}</span>
                    </x-bs-admin::button>
                @else
                    <x-bs-admin::button data-te-action="{{ $primary['action'] }}"
                        :aria-disabled="$page['retryAfter'] !== null ? 'true' : null"
                        :aria-describedby="$whyId"
                        :title="$page['retryAfter'] !== null ? __('errors.rate.btn_wait', ['seconds' => $page['retryAfter']]) : null">
                        <x-bs-admin::icon :name="$primary['icon']" class="size-4" /><span>{{ $primary['label'] }}</span>
                    </x-bs-admin::button>
                @endif
            @endif
            @foreach ($page['secondary'] as $second)
                <x-bs-admin::button variant="ghost" :href="$second['href']" :navigate="false">
                    <x-bs-admin::icon :name="$second['icon']" class="size-4" /><span>{{ $second['label'] }}</span>
                </x-bs-admin::button>
            @endforeach
        </div>
    @endif

    @if ($page['why'] !== null)
        <p class="te-why" id="{{ $whyId }}" data-te-why>{{ $page['why'] }}</p>
    @endif

    @if ($page['code'] !== null)
        <div class="te-box">
            <div class="te-box-t">{{ __('errors.code_title') }}</div>
            <div class="te-code">
                <b aria-label="{{ $codeSpell }}">{{ $page['code'] }}</b>
                <x-bs-admin::button size="sm" variant="outline" color="secondary" data-te-copy="{{ $page['code'] }}"
                    data-te-copied="{{ __('errors.code_copied', ['code' => $page['code']]) }}"
                    data-te-copy-failed="{{ __('errors.code_copy_failed', ['code' => $page['code']]) }}">
                    <x-bs-admin::icon name="ri-file-copy" class="size-4" /><span>{{ __('errors.code_copy') }}</span>
                </x-bs-admin::button>
            </div>
        </div>
    @endif

    @if ($page['support'] !== null)
        <div class="te-box" id="ho-tro">
            <div class="te-box-t">{{ $page['support']['title'] }}</div>
            <div class="te-chs">
                @foreach ($page['support']['channels'] as $channel)
                    <a class="te-link" href="{{ $channel['href'] }}"><x-bs-admin::icon :name="$channel['icon']" class="size-4" />{{ $channel['label'] }}</a>
                @endforeach
            </div>
        </div>
    @endif
</div>
