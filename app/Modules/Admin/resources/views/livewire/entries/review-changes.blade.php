{{--
    «Thay đổi của bài đang đăng» — board 17's card in the review inbox, on the admin tokens: the pending
    changes to posts already on the website that wait for this person. Shown only while there is one.
    Each row: what it is, its title, its step / section / who / when / how many edits / languages, then
    «So sánh» and the moves (a one-step flow: «Trả lại» · «Duyệt đăng»; more steps: the flow's own moves,
    the note ones first, going live last), or the line saying why there is none.

    One dialog per step: «So sánh: …» (each field that differs, the new value over what visitors see),
    «Duyệt đăng thay đổi?» (asked first) and the return note (required). Below 600px they are sheets
    from the bottom.
--}}
@php
    $Js = \Illuminate\Support\Js::class;
    $btnIcon = 'size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]';
@endphp
{{-- display: contents, so the inbox's own spacing sees the card and the message, not an empty box when there is neither --}}
<div class="contents" data-inbox-changes>
    @error('changes')
        <x-bs-admin::alert color="danger" icon="ri-error-warning" class="mb-4" data-chg-error>{{ $message }}</x-bs-admin::alert>
    @enderror

    @if ($rows !== [])
        <section class="mb-4 rounded-md border border-border bg-surface shadow-card" aria-labelledby="chg-title" data-chg-card>
            <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <h2 id="chg-title" class="m-0 text-body font-semibold text-text-strong">{{ __('admin.inbox.chg_title') }}</h2>
                <span class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-caption font-bold text-white" data-chg-count>{{ $total > $countMax ? $countMax.'+' : $total }}</span>
            </div>
            <p class="m-0 px-4 pt-3 pb-3 text-small leading-relaxed text-text-muted">{{ __('admin.inbox.chg_desc') }}</p>

            <ul class="m-0 list-none p-0">
                @foreach ($rows as $row)
                    <li wire:key="chg-{{ $row['ulid'] }}" data-chg="{{ $row['ulid'] }}"
                        class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-3">
                        <div class="flex min-w-0 flex-[1_1_280px] flex-col gap-1">
                            <span class="inline-flex max-w-full items-center gap-1 self-start rounded-full bg-[color-mix(in_srgb,var(--color-secondary-text)_10%,var(--color-surface))] px-2 py-0.5 text-caption font-semibold text-secondary-text [overflow-wrap:anywhere]">
                                <x-bs-admin::icon name="ri-git-pull-request" class="size-[calc(0.875rem+var(--user-text-boost))]" />{{ __('admin.inbox.chg_title') }}
                            </span>
                            <b class="text-body font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $row['title'] }}</b>
                            <span class="text-small leading-normal text-text-muted [overflow-wrap:anywhere]">{{ $row['meta'] }}</span>
                        </div>
                        <div class="flex max-w-full flex-[0_1_auto] flex-wrap gap-2 max-[600px]:flex-[1_1_100%] max-[600px]:*:flex-auto">
                            <x-bs-admin::button variant="outline" color="neutral" :aria-label="__('admin.inbox.chg_compare_name', ['title' => $row['title']])"
                                                wire:click="openChange({{ $Js::from($row['ulid']) }}, 'view')" wire:loading.attr="disabled" data-chg-view>
                                <x-bs-admin::icon name="ri-git-commit" :class="$btnIcon" />{{ __('entries.chg.btn_compare') }}
                            </x-bs-admin::button>
                            @if ($row['pair'])
                                @if ($row['return'] !== null)
                                    <x-bs-admin::button variant="outline" color="neutral" wire:click="openChange({{ $Js::from($row['ulid']) }}, 'return')" wire:loading.attr="disabled" data-chg-return>
                                        <x-bs-admin::icon name="ri-reply" :class="$btnIcon" />{{ __('entries.chg.btn_return') }}
                                    </x-bs-admin::button>
                                @endif
                                <x-bs-admin::button color="primary" wire:click="openChange({{ $Js::from($row['ulid']) }}, 'approve')" wire:loading.attr="disabled" data-chg-approve>
                                    <x-bs-admin::icon name="ri-check-double" :class="$btnIcon" />{{ __('entries.chg.btn_approve') }}
                                </x-bs-admin::button>
                            @endif
                            @foreach ($row['moves'] as $move)
                                @include('admin::livewire.entries.partials.review-change-move', ['ulid' => $row['ulid'], 'move' => $move, 'inDialog' => false])
                            @endforeach
                        </div>
                        @if ($row['why'] !== null)
                            <span class="flex flex-[1_1_100%] items-start gap-1.5 text-small text-text-muted" data-chg-why>
                                <x-bs-admin::icon name="ri-lock" class="mt-[3px] size-[calc(0.875rem+var(--user-text-boost))] shrink-0" />{{ $row['why'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($more)
                <div class="flex justify-center border-t border-border px-4 py-3">
                    <x-bs-admin::button color="light" size="sm" wire:click="loadMoreChanges" wire:loading.attr="disabled" wire:target="loadMoreChanges" data-chg-more>
                        <x-bs-admin::icon name="ri-arrow-down" class="size-4" />@php $left = max(1, min($page, $total - count($rows))); @endphp{{ trans_choice('admin.inbox.chg_more', $left, ['count' => $left]) }}
                    </x-bs-admin::button>
                </div>
            @endif
        </section>
    @endif

    @if ($dialog !== null && $dialog['mode'] === 'view')
        <x-bs-admin::modal model="compareOpen" size="wide" :title="$dialog['title']" :close-label="__('admin.close')" wire:key="chg-view-{{ $dialog['ulid'] }}">
            <div class="flex items-start gap-2 text-body leading-relaxed">
                <x-bs-admin::icon name="ri-information" class="mt-[3px] size-4 shrink-0 text-text-muted" /><span>{{ $dialog['line'] }}</span>
            </div>
            @if ($dialog['diff'] === [])
                <p class="m-0 text-text-muted">{{ __('entries.chg.diff_none') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col gap-2.5 p-0" data-change-diff>
                    @foreach ($dialog['diff'] as $field)
                        <li class="flex min-w-0 flex-col gap-[3px] rounded border border-[color-mix(in_srgb,var(--color-warning-text)_40%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-warning-text)_8%,var(--color-surface))] px-2.5 py-2">
                            <span class="flex flex-wrap items-center gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text-muted">
                                @if ($field['lang'] !== null)
                                    <span class="inline-flex rounded-full bg-surface-hover px-2 py-px text-caption font-semibold text-text">{{ $field['lang'] }}</span>
                                @endif
                                {{ $field['label'] }}
                            </span>
                            <span class="text-text-strong [overflow-wrap:anywhere]">{{ $field['now'] }}</span>
                            <span class="text-small text-text-muted [overflow-wrap:anywhere]">{{ __('entries.chg.diff_seen', ['text' => $field['seen']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($dialog['pair'] || $dialog['moves'] !== [])
                <x-slot:footer>
                    @if ($dialog['pair'])
                        @if ($dialog['return'])
                            <x-bs-admin::button variant="outline" color="neutral" wire:click="switchChange('return')" wire:loading.attr="disabled" data-chg-dlg-return>
                                <x-bs-admin::icon name="ri-reply" :class="$btnIcon" />{{ __('entries.chg.btn_return') }}
                            </x-bs-admin::button>
                        @endif
                        <x-bs-admin::button color="primary" wire:click="switchChange('approve')" wire:loading.attr="disabled" data-chg-dlg-approve>
                            <x-bs-admin::icon name="ri-check-double" :class="$btnIcon" />{{ __('entries.chg.btn_approve') }}
                        </x-bs-admin::button>
                    @endif
                    @foreach ($dialog['moves'] as $move)
                        @include('admin::livewire.entries.partials.review-change-move', ['ulid' => $dialog['ulid'], 'move' => $move, 'inDialog' => true])
                    @endforeach
                </x-slot:footer>
            @endif
        </x-bs-admin::modal>
    @endif

    @if ($dialog !== null && $dialog['mode'] === 'approve')
        <x-bs-admin::confirm-dialog model="approveOpen" :title="$dialog['title']" icon="ri-global" :ok="$dialog['ok']" ok-icon="ri-check-double"
                                    confirm="confirmChange" cancel="closeChange">
            <x-bs-admin::confirm-line icon="ri-global">{{ __('entries.chg.ask_langs', ['langs' => $dialog['langs']]) }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-eye">{{ __('entries.chg.ask_now') }}</x-bs-admin::confirm-line>
        </x-bs-admin::confirm-dialog>
    @endif

    @if ($dialog !== null && $dialog['mode'] === 'return')
        <x-bs-admin::modal model="returnOpen" size="narrow" :title="$dialog['title']" :close-label="__('admin.close')" alert wire:key="chg-return-{{ $dialog['ulid'] }}">
            <div class="flex items-start gap-2 text-body leading-relaxed">
                <x-bs-admin::icon name="ri-information" class="mt-[3px] size-4 shrink-0 text-text-muted" /><span>{{ $dialog['line'] }}</span>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="chg-note" class="text-small font-semibold text-text-strong">{{ __('entries.chg.return_label', ['by' => $dialog['by']]) }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                <textarea id="chg-note" wire:model="note" rows="3" maxlength="2000" required autofocus aria-describedby="chg-note-err"
                          placeholder="{{ __('admin.inbox.chg_note_placeholder') }}"
                          aria-invalid="{{ $errors->has('note') ? 'true' : 'false' }}"
                          class="w-full resize-y rounded border bg-surface px-3 py-2 text-body leading-normal text-text focus:border-primary-text {{ $errors->has('note') ? 'border-danger-text' : 'border-border-input' }}"></textarea>
                <div id="chg-note-err">
                    @error('note') <x-bs-admin::field-error role="alert">{{ $message }}</x-bs-admin::field-error> @enderror
                </div>
            </div>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="neutral" wire:click="closeChange">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" wire:click="confirmChange" wire:loading.attr="disabled" wire:target="confirmChange" data-chg-return-ok>
                    <x-bs-admin::icon name="ri-reply" :class="$btnIcon" />{{ $dialog['ok'] }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    @endif
</div>
