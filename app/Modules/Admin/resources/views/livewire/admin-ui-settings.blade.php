@php
    use App\Modules\Admin\Theme\AdminChromeResolver;

    // Design board «Khung quản trị», section «Giao diện trang quản trị»: a note, then two folding groups of
    // settings, each drawn as picture cards; the first card keeps the platform's default and names it (X),
    // so the owner sees what the default resolves to. Only the values the panel can draw today are offered.
    $groups = [
        'layout' => ['layout_width', 'position', 'sidebar_size'],
        'look' => ['scheme', 'topbar', 'sidebar', 'sidebar_image', 'text_size'],
    ];
    $opt = fn (string $axis, string $value): string => $axis === 'text_size' ? __('admin.account.fs_'.$value) : __('admin.aui.o.'.$axis.'.'.$value);
    $bad = $errors->keys();
@endphp
<x-bs-admin::settings-shell active="admin-ui" :unsaved="$changes" :invalid="count($bad)">
    <x-slot:more><x-bs-admin::settings-restore-more :name="__('admin.admin_ui')" /></x-slot:more>
    @if ($saved)
        <x-bs-admin::alert color="success" icon="ri-checkbox-circle">{{ __('admin.theme_saved_note') }}</x-bs-admin::alert>
    @endif
    {{-- Enter in a field is the save bar's "Lưu" (it waits while a save runs and does nothing with nothing to save); the form itself never submits, so the page never reloads --}}
    <div x-data="{ open: 'layout' }" class="flex flex-col gap-4">
    <x-bs-admin::settings-stamp :stamp="$stamp" />
    <form x-data x-on:submit.prevent class="flex flex-col gap-3">
        <p class="flex items-start gap-2 rounded-md border border-info-text/30 bg-info-soft px-3 py-2.5 text-caption leading-relaxed text-info-text" role="note">
            <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
            <span class="min-w-0">{{ __('admin.aui.note') }}</span>
        </p>

        @foreach ($groups as $group => $axes)
            <x-bs-admin::settings-group :key="$group" id="aui-g-{{ $group }}" :title="__('admin.aui.g_'.$group)" :count="count($axes)"
                                        :bad="count(array_intersect($axes, $bad))" :first="$loop->first"
                                        class="flex flex-col gap-6">
                @foreach ($axes as $axis)
                    @php
                        $fid = 'aui-'.$axis;
                        $labels = [];
                        foreach (AdminChromeResolver::FIELDS[$axis] as $value) {
                            $labels[$value] = $opt($axis, $value);
                        }
                    @endphp
                    <div class="flex min-w-0 flex-col gap-2.5" wire:key="aui-f-{{ $axis }}">
                        <div class="flex flex-col gap-0.5">
                            <h3 id="{{ $fid }}" class="text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.aui.f_'.$axis) }}</h3>
                            <p id="{{ $fid }}-d" class="text-caption text-text-muted">{{ __('admin.aui.d_'.$axis) }}</p>
                        </div>
                        <x-bs-admin::pick-cards :axis="$axis" :values="AdminChromeResolver::FIELDS[$axis]" :labels="$labels" :labelledby="$fid"
                                                :inherit="__('admin.aui.inherit_card')" aria-describedby="{{ $fid }}-d" />
                        @error($axis)
                            <x-bs-admin::field-error>{{ $message }}</x-bs-admin::field-error>
                        @enderror
                    </div>
                @endforeach
            </x-bs-admin::settings-group>
        @endforeach
    </form>
    </div>
    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" :undo="$undo !== null ? 'undoRestore' : null" />
</x-bs-admin::settings-shell>
