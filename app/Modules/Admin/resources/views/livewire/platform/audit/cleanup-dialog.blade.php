@php
    use App\Modules\Admin\Livewire\Platform\Audit\CleanupDialog;
    use App\Modules\Audit\Support\AuditMonths;

    $num = fn (int $n): string => \Illuminate\Support\Number::format($n, locale: app()->getLocale());
    $pick = $step === 'pick';
    $loading = $pick && $months === null && ! $loadFailed;
    $listed = $pick && $months !== null;
    $none = $listed && $removable === [];
    $counting = ! $pick && $exact === null && ! $countFailed;
    $label = CleanupDialog::label($month);
    $danger = 'flex items-start gap-2 rounded border border-danger-base/25 bg-danger-soft px-4 py-3 text-body text-danger-text';
    $info = 'flex items-start gap-2 rounded border border-info-base/25 bg-info-soft px-4 py-3 text-body text-info-text';
    $off = 'aria-disabled:cursor-not-allowed aria-disabled:opacity-60';
    $noneText = __('admin.audit.purge.none_text', ['months' => AuditMonths::MANUAL_FLOOR_MONTHS])
        .($oldest === null ? '' : ' '.__('admin.audit.purge.none_oldest', ['oldest' => CleanupDialog::label($oldest), 'from' => CleanupDialog::label((string) $oldestFrom)]));
@endphp
{{-- "Dọn nhật ký" on the audit screen. The screen opens it with $dispatch('open-audit-cleanup'). --}}
<div x-on:open-audit-cleanup.window="pw = ''; $wire.openDialog()"
     x-data="{
         pw: '',
         busy: false,
         radio() { const box = document.getElementById('audit-cleanup-months'); return box?.querySelector('input:checked') ?? box?.querySelector('input'); },
         after() {
             const field = document.getElementById('audit-cleanup-pw'), err = document.getElementById('audit-cleanup-pw-err'), alert = document.getElementById('audit-cleanup-alert');
             if (! field) { this.pw = ''; document.getElementById('audit-cleanup-open')?.focus(); return; }
             if (err) { field.focus(); field.select(); err.scrollIntoView({ block: 'nearest' }); return; }
             alert?.focus();
         },
         go() {
             if (this.busy || $wire.monthRefused || $wire.exact === null) return;
             this.busy = true;
             $wire.purge(this.pw).then(() => $nextTick(() => this.after())).finally(() => { this.busy = false; });
         },
         next() { $wire.next().then(() => $nextTick(() => { if (document.getElementById('audit-cleanup-pick-err')) this.radio()?.focus(); })); },
         toPick() { this.pw = ''; $wire.back().then(() => $nextTick(() => this.radio()?.focus())); },
     }">
    @if ($open)
        <x-bs-admin::drawer :title="__('admin.audit.purge.title')"
                            :subtitle="__($pick ? 'admin.audit.purge.step_pick' : 'admin.audit.purge.step_confirm')"
                            close="close" return-to="audit-cleanup-open" busy="purge"
                            :close-label="__('admin.audit.purge.close_aria')" wire:key="audit-cleanup">
            @if ($pick)
                <p class="m-0 [text-wrap:pretty]">{{ __('admin.audit.purge.intro') }}</p>
                <p class="m-0 flex items-start gap-2 text-small text-text-muted">
                    <x-bs-admin::icon name="ri-calendar-check" class="mt-0.5 size-4 shrink-0" />
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.audit.purge.optional', ['months' => AuditMonths::KEEP_MONTHS]) }}</span>
                </p>

                @if ($loading)
                    {{-- the months are read once the panel is up, so it opens at once --}}
                    <div aria-hidden="true" class="flex flex-col gap-2" wire:key="audit-cleanup-loading"
                         x-init="$wire.loadMonths().then(() => $nextTick(() => radio()?.focus()))">
                        @foreach (range(1, 4) as $k)
                            <div class="h-hit rounded bg-surface-hover"></div>
                        @endforeach
                    </div>
                    <p class="sr-only" role="status">{{ __('admin.audit.purge.loading') }}</p>
                @elseif ($loadFailed)
                    <div role="alert" class="{{ $danger }}">
                        <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-5 shrink-0" />
                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.audit.purge.load_error') }}</span>
                    </div>
                    <div>
                        <x-bs-admin::button variant="outline" color="secondary" wire:click="retry">
                            <x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.audit.retry') }}
                        </x-bs-admin::button>
                    </div>
                @elseif ($none)
                    <div>
                        <h3 class="m-0 mb-1 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.audit.purge.none_title') }}</h3>
                        <p class="m-0 [text-wrap:pretty]">{{ $noneText }}</p>
                    </div>
                @else
                    <fieldset id="audit-cleanup-months" class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0"
                              @if ($pickError) aria-describedby="audit-cleanup-pick-err" @endif>
                        <legend class="float-left mb-1.5 w-full p-0 text-small font-semibold text-text-strong">{{ __('admin.audit.purge.legend') }}</legend>
                        @foreach ($removable as $m)
                            <label wire:key="audit-cleanup-{{ $m['month'] }}"
                                   class="flex min-h-hit cursor-pointer items-start gap-2.5 rounded-md border border-border-strong px-3 py-2.5 has-checked:border-accent has-checked:bg-accent/6">
                                <input type="radio" name="audit-cleanup-month" value="{{ $m['month'] }}" wire:model.live="month"
                                       class="mt-0.5 size-4.5 shrink-0 accent-primary">
                                <span class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-3 [overflow-wrap:anywhere]">
                                    <b class="font-semibold text-text-strong">{{ __('admin.audit.purge.month', ['month' => CleanupDialog::label($m['month'])]) }}</b>
                                    <span class="text-text-muted">{{ __('admin.audit.purge.estimate', ['n' => $num($m['rows_estimate'])]) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                    @if ($pickError)
                        <p id="audit-cleanup-pick-err" class="m-0 -mt-2 flex items-start gap-1.5 text-small text-danger-text">
                            <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.audit.purge.invalid_month') }}</span>
                        </p>
                    @endif
                @endif

                @if ($listed && $kept !== [])
                    <div role="note" class="{{ $info }}">
                        <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-5 shrink-0" />
                        <span class="min-w-0 [overflow-wrap:anywhere]"><b>{{ __('admin.audit.purge.kept_range', ['from' => CleanupDialog::label($kept[0]['month']), 'to' => CleanupDialog::label($kept[count($kept) - 1]['month'])]) }}</b>
                            {{ __('admin.audit.purge.kept_count', ['n' => $num(count($kept)), 'rows' => $num($keptRows)]) }}
                            {{ __('admin.audit.purge.kept_why', ['months' => AuditMonths::MANUAL_FLOOR_MONTHS]) }}</span>
                    </div>
                @endif
            @else
                @if ($counting)
                    <div aria-hidden="true" class="flex flex-col gap-2" wire:key="audit-cleanup-counting"
                         x-init="$wire.countRows().then(() => $nextTick(() => document.getElementById('audit-cleanup-pw')?.focus()))">
                        <div class="h-[calc(4rem+var(--user-text-boost)*4)] rounded bg-surface-hover"></div>
                        <div class="h-3.5 w-3/5 rounded bg-surface-hover"></div>
                    </div>
                    <p class="m-0 text-small text-text-muted" role="status">{{ __('admin.audit.purge.counting', ['month' => $label]) }}</p>
                @elseif ($countFailed)
                    <div role="alert" class="{{ $danger }}">
                        <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-5 shrink-0" />
                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.audit.purge.count_error', ['month' => $label]) }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-bs-admin::button variant="outline" color="secondary" wire:click="retry">
                            <x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.audit.retry') }}
                        </x-bs-admin::button>
                        <x-bs-admin::button variant="ghost" x-on:click="toPick()">
                            <x-bs-admin::icon name="ri-arrow-left" class="size-4" />{{ __('admin.audit.purge.back') }}
                        </x-bs-admin::button>
                    </div>
                @else
                    <div class="{{ $danger }}">
                        <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-5 shrink-0" />
                        <span class="min-w-0 [overflow-wrap:anywhere]">
                            <span class="mb-1 block text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug">{{ __('admin.audit.purge.confirm_title', ['month' => $label, 'n' => $num((int) $exact)]) }}</span>
                            {{ __('admin.audit.purge.confirm_text') }}
                        </span>
                    </div>
                    <p class="m-0 flex items-start gap-2 text-small text-text-muted">
                        <x-bs-admin::icon name="ri-file-list-3" class="mt-0.5 size-4 shrink-0" />
                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.audit.purge.recorded') }}</span>
                    </p>
                    @unless ($monthRefused)
                        <div>
                            <x-bs-admin::button variant="ghost" x-on:click="if (! busy) toPick()" class="{{ $off }}"
                                                wire:loading.attr="aria-disabled" wire:target="purge">
                                <x-bs-admin::icon name="ri-arrow-left" class="size-4" />{{ __('admin.audit.purge.back') }}
                            </x-bs-admin::button>
                        </div>
                    @endunless
                    <div>
                        <label for="audit-cleanup-pw" class="mb-1.5 block text-small font-semibold text-text-strong">{{ __('admin.audit.purge.password') }}</label>
                        <input id="audit-cleanup-pw" type="password" autocomplete="current-password" x-model="pw"
                               x-on:keydown.enter.prevent="go()" wire:loading.attr="readonly" wire:target="purge"
                               @if ($passwordError !== '') aria-invalid="true" aria-describedby="audit-cleanup-pw-err" @endif
                               class="field block w-full rounded border border-border-input bg-surface px-3 text-text transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                        @if ($passwordError !== '')
                            <p id="audit-cleanup-pw-err" class="m-0 mt-1.5 flex items-start gap-1.5 text-small text-danger-text">
                                <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ $passwordError }}</span>
                            </p>
                        @endif
                    </div>
                    @if ($monthError !== '')
                        <div id="audit-cleanup-alert" role="alert" tabindex="-1" class="{{ $danger }} flex-col">
                            <span class="flex items-start gap-2">
                                <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-5 shrink-0" />
                                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $monthError }}</span>
                            </span>
                            @if ($monthRefused)
                                <x-bs-admin::button variant="outline" color="secondary" x-on:click="toPick()">
                                    <x-bs-admin::icon name="ri-arrow-left" class="size-4" />{{ __('admin.audit.purge.back') }}
                                </x-bs-admin::button>
                            @endif
                        </div>
                    @endif
                @endif
            @endif

            <x-slot:footer>
                @if ($pick && ($loadFailed || $none))
                    <x-bs-admin::button x-on:click="hide()">{{ __('admin.close') }}</x-bs-admin::button>
                @elseif ($pick)
                    <x-bs-admin::button variant="outline" color="secondary" x-on:click="hide()">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button x-on:click="next()" class="{{ $off }}" :aria-disabled="$loading ? 'true' : 'false'">
                        {{ __('admin.audit.purge.next') }}<x-bs-admin::icon name="ri-arrow-right" class="size-4" />
                    </x-bs-admin::button>
                @else
                    <x-bs-admin::button variant="outline" color="secondary" x-on:click="hide()" class="{{ $off }}"
                                        wire:loading.attr="aria-disabled" wire:target="purge">{{ __('admin.cancel') }}</x-bs-admin::button>
                    {{-- disabled while counting, deleting, or once the month itself was refused --}}
                    <x-bs-admin::button color="danger" x-on:click="go()" class="{{ $off }}"
                                        x-bind:aria-disabled="busy || $wire.exact === null || $wire.monthRefused ? 'true' : 'false'">
                        <x-bs-admin::icon name="ri-delete-bin" class="size-4" wire:loading.remove wire:target="purge" />
                        <x-bs-admin::icon name="ri-loader-4" class="size-4 animate-spin motion-reduce:animate-none" wire:loading wire:target="purge" />
                        <span wire:loading.remove wire:target="purge">{{ __('admin.audit.purge.submit', ['month' => $label]) }}</span>
                        <span wire:loading wire:target="purge">{{ __('admin.audit.purge.deleting') }}</span>
                    </x-bs-admin::button>
                @endif
            </x-slot:footer>
        </x-bs-admin::drawer>
    @endif

    <x-bs-admin::toast :message="$toast" dismiss="dismissToast" />
</div>
