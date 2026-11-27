{{-- «Dung lượng đã dùng» (design board 12): how much the library stores against the plan, from the
     running totals (Library::storageMeter()). $part: 'card' the card under the upload panel, 'side' the
     compact meter at the foot of the folder column (level ok, wide screen), 'box' the upload panel's
     «Đã hết dung lượng» box. `full` renders only while the storage switch refuses uploads. The Trash
     buttons show to those who can open the Trash; the second button of the full state writes to
     support (there is no plans page for a website yet). --}}
@php
    $lv = $storage['level'];
    $barTone = ['ok' => 'bg-admin-primary', 'warn' => 'bg-admin-warning', 'full' => 'bg-admin-danger'][$lv];
    $usedLine = __('media.lib.st_used', ['used' => '<b class="font-semibold">'.e($storage['used']).'</b>', 'limit' => e($storage['limit'])]);
    $aria = __('media.lib.st_aria', ['used' => $storage['used'], 'limit' => $storage['limit']]);
    $fullText = __($upsell ? 'media.lib.st_full_up' : 'media.lib.st_full_off', ['trash' => $storage['trash']]);
    $bar = '<div class="h-2 overflow-hidden rounded bg-surface-hover" role="progressbar" aria-label="'.e(__('media.lib.st_label')).'" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.$storage['pct'].'" aria-valuetext="'.e($aria).'"><span class="block h-full rounded '.$barTone.'" style="width: '.$storage['pct'].'%"></span></div>';
    $contactHref = 'mailto:'.$supportMail;
    // level ok: on a narrow screen the card goes to the page end; on a wide one the folder column's meter
    // stands for it (with the upload panel open, or no folder column yet, it stays here)
    $aside = $lv === 'ok' && ! $uploadOpen;
    $sideShown = $aside && ($counts['all'] + $counts['trash'] > 0);
@endphp
@if ($part === 'side')
    <div class="mx-1 mt-3 flex flex-col gap-1.5 border-t border-border pt-3" role="group" aria-label="{{ __('media.lib.st_label') }}">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-body text-text-strong"><x-bs-admin::icon name="ri-hard-drive-2" class="size-4 shrink-0" /><span>{!! $usedLine !!}</span></span>
        {!! $bar !!}
        <span class="text-body text-text-muted">{{ $storage['status'] }}</span>
    </div>
@elseif ($part === 'box')
    <div class="flex flex-col items-center gap-3 px-4 py-8 text-center">
        <span class="flex size-12 items-center justify-center rounded-full bg-danger/10 text-danger-text"><x-bs-admin::icon name="ri-hard-drive-2" class="size-6" /></span>
        <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('media.lib.st_full_title') }}</h2>
        <p class="m-0 max-w-[52ch] text-body text-text">{{ $fullText }}</p>
        <div class="flex flex-wrap justify-center gap-2">
            @if ($canDelete)
                <x-bs-admin::button color="primary" wire:click="openTrash"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ __('media.lib.st_clean_trash') }}</x-bs-admin::button>
            @endif
            <x-bs-admin::button variant="outline" color="secondary" :href="$contactHref" :navigate="false"><x-bs-admin::icon :name="$upsell ? 'ri-vip-crown' : 'ri-customer-service-2'" class="size-4" />{{ __($upsell ? 'media.lib.st_see_plans' : 'media.lib.st_support') }}</x-bs-admin::button>
        </div>
    </div>
@else
    <section @class([
        'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-border bg-surface px-4 py-3 shadow-card',
        '@max-[48rem]/lib:order-last @max-[48rem]/lib:px-3 @max-[48rem]/lib:py-2' => $aside,
        '@min-[48rem]/lib:hidden' => $sideShown,
    ]) data-lv="{{ $lv }}" aria-label="{{ __('media.lib.st_label') }}">
        <div class="flex min-w-0 flex-[1_1_16rem] flex-col gap-1.5">
            <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-body text-text-strong">
                <x-bs-admin::icon :name="['ok' => 'ri-hard-drive-2', 'warn' => 'ri-alert', 'full' => 'ri-error-warning-fill'][$lv]" @class(['size-4 shrink-0', 'text-admin-warning-text' => $lv === 'warn', 'text-admin-danger-text' => $lv === 'full']) />
                <span>{!! $usedLine !!}</span>
            </span>
            {!! $bar !!}
            <span @class([
                'inline-flex items-center gap-1.5 text-body',
                'text-text-muted' => $lv === 'ok',
                'font-semibold text-admin-warning-text' => $lv === 'warn',
                'font-semibold text-admin-danger-text' => $lv === 'full',
            ])>@if ($lv === 'warn')<x-bs-admin::icon name="ri-alert" class="size-4 shrink-0" />@endif{{ $storage['status'] }}</span>
        </div>
        @if ($lv === 'warn' && $canDelete)
            <x-bs-admin::button size="sm" variant="outline" color="secondary" class="shrink-0" wire:click="openTrash"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ __('media.lib.st_open_trash') }}</x-bs-admin::button>
        @endif
        @if ($lv === 'full' && $canUpload)
            <x-bs-admin::alert color="danger" id="tv-full" tabindex="-1" class="flex-[1_1_20rem]">
                <div class="flex flex-col gap-2">
                    <span><b class="font-semibold">{{ __('media.lib.st_full_title') }}.</b> {{ $fullText }}</span>
                    <div class="flex flex-wrap gap-2">
                        @if ($canDelete)
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openTrash"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ __('media.lib.st_clean_trash') }}</x-bs-admin::button>
                        @endif
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" :href="$contactHref" :navigate="false"><x-bs-admin::icon :name="$upsell ? 'ri-vip-crown' : 'ri-customer-service-2'" class="size-4" />{{ __($upsell ? 'media.lib.st_see_plans' : 'media.lib.st_support') }}</x-bs-admin::button>
                    </div>
                </div>
            </x-bs-admin::alert>
        @endif
    </section>
@endif
