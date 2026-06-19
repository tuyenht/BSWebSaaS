@props(['active' => ''])
@php
    $nav = [[
        'group' => __('admin.settings_account'),
        'items' => [
            ['key' => 'security', 'route' => 'admin.settings.security', 'label' => __('admin.security')],
        ],
    ]];

    $workspace = [];
    if (Route::has('admin.settings.email')) {
        $workspace[] = ['key' => 'email', 'route' => 'admin.settings.email', 'label' => __('admin.email_settings')];
    }
    if (Route::has('admin.settings.social')) {
        $workspace[] = ['key' => 'social', 'route' => 'admin.settings.social', 'label' => __('admin.social_login')];
    }
    if ($workspace !== []) {
        $nav[] = ['group' => __('admin.settings_workspace'), 'items' => $workspace];
    }
@endphp
<div class="mx-auto max-w-5xl">
    <h1 class="mb-5 text-lg font-semibold text-gray-800">{{ __('admin.settings') }}</h1>

    <div class="grid gap-6 lg:grid-cols-[210px_1fr]">
        {{-- Section nav: vertical on desktop, horizontally scrollable on mobile --}}
        <nav class="lg:sticky lg:top-20 lg:self-start">
            <div class="-mx-1 flex gap-1 overflow-x-auto pb-1 lg:mx-0 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:pb-0">
                @foreach ($nav as $group)
                    <p class="hidden shrink-0 px-2 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 first:lg:pt-0 lg:block">{{ $group['group'] }}</p>
                    @foreach ($group['items'] as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate
                           @class([
                               'shrink-0 whitespace-nowrap rounded px-3 py-2 text-sm font-medium transition-colors',
                               'bg-admin-primary/10 text-admin-primary' => $active === $item['key'],
                               'text-gray-600 hover:bg-gray-100' => $active !== $item['key'],
                           ])>{{ $item['label'] }}</a>
                    @endforeach
                @endforeach
            </div>
        </nav>

        {{-- Section content --}}
        <div class="min-w-0 space-y-6">{{ $slot }}</div>
    </div>
</div>
