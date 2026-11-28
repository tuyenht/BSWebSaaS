<div>
    <x-bs-admin::screen-head class="mb-4" :title="__('admin.platform_sites')" top
        :crumb="[['label' => __('admin.platform_sites')]]"
        :desc="__('admin.psites.desc')" :mdesc="__('admin.psites.mdesc')"
        :guide="\App\Modules\Admin\Support\Guide\ScreenGuide::print('sites', __('admin.platform_sites'))" />

    {{-- Filters --}}
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <input type="search" data-guide="search" aria-label="{{ __('admin.search_placeholder_4') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('admin.search_placeholder_4') }}"
               class="field w-full rounded border border-border bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text focus:bg-surface sm:max-w-xs">
        <select data-guide="filter" aria-label="{{ __('admin.status') }}" wire:model.live="status"
                class="field w-full rounded border border-border bg-surface-muted px-3 text-text transition-colors focus:border-primary-text focus:bg-surface sm:max-w-[200px]">
            <option value="">{{ __('admin.all_statuses') }}</option>
            <option value="active">{{ __('admin.status_active') }}</option>
            <option value="provisioning">{{ __('admin.status_provisioning') }}</option>
            <option value="suspended">{{ __('admin.status_suspended') }}</option>
            <option value="archived">{{ __('admin.status_archived') }}</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-surface-muted text-left text-xs uppercase tracking-wider text-body-muted">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.name') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.primary_domain') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.tenant') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.site_type') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.status') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-soft">
                @forelse ($sites as $site)
                    <tr wire:key="site-{{ $site->id }}">
                        <td class="px-4 py-3 font-medium text-heading">{{ $site->name }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $site->domains->firstWhere('is_primary', true)?->hostname ?? $site->domains->first()?->hostname ?? __('admin.no_domain') }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $site->tenant?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $site->siteType?->key ?? '—' }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $site->status ? __('admin.status_'.$site->status) : '—' }}</td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('platform.sites.show', $site->ulid) }}" wire:navigate data-guide="row-open"
                               class="font-medium text-accent transition-colors hover:underline">{{ __('admin.view') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-placeholder">{{ __('admin.no_sites') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $sites->links() }}
    </div>
</div>
