{{-- «Thông tin tuyển dụng» (board 07), behind «Thêm tùy chọn», for a post whose main category lists its posts as jobs
     (Editor::jobCard, EditsJobCard). The preview of the job card, the short boxes in this language (at most four with
     «Hạn nộp», 40 characters each, «Nổi bật» for the accent tone, «Bỏ ô» with «Hoàn tác»), the suggestion buttons,
     «Hạn nộp» as a day and «Nhãn trên thẻ» (24 characters). The words are sent with the next save; the counters and
     the preview follow the typing in the browser, the boxes themselves come from the server. --}}
@php
    $jobCount = __('entries.ed.job_count', ['n' => '__N__', 'max' => \App\Modules\Cms\Support\JobFacts::FACT_TEXT_MAX]);
    $badgeCount = __('entries.ed.job_count', ['n' => '__N__', 'max' => \App\Modules\Cms\Support\JobFacts::BADGE_MAX]);
    $jobLen = static fn (string $text): int => \App\Modules\Admin\Support\Entries\JobCardForm::length($text);
    $previewAny = $jobCard['chip'] !== '' || collect($jobCard['facts'])->contains(static fn (array $fact): bool => $jobLen($fact['text']) > 0);
    $pill = 'inline-flex max-w-full items-center gap-1 rounded-full border px-2 text-[calc(0.75rem+var(--user-text-boost))] leading-[1.6] [overflow-wrap:anywhere]';
    $pillPlain = 'border-border-strong bg-surface text-text';
    $pillAccent = 'border-accent bg-surface font-semibold text-accent';
    $hint = 'm-0 text-caption text-text-muted [overflow-wrap:anywhere]';
    $boxLabel = 'text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text';
    $quiet = 'aria-disabled:cursor-not-allowed aria-disabled:opacity-60';
@endphp
<section id="entry-job" class="{{ $card }}" aria-labelledby="entry-job-h" data-entry-job x-show="more" x-cloak
         x-data="{
             len(s) { return [...String(s ?? '').trim()].length },
             fact(i) { const f = (this.$wire.jobFacts || [])[i]; return f ? String(f.text ?? '').trim() : '' },
             badge() { return String(this.$wire.jobBadge ?? '').trim() },
             any() { return {{ $jobCard['chip'] !== '' ? 'true' : 'false' }} || (this.$wire.jobFacts || []).some((f, i) => this.fact(i) !== '') },
             count(words, n) { return words.replace('__N__', n) },
         }">
    <div class="{{ $cardHead }}">
        <h2 id="entry-job-h" class="{{ $cardTitle }}">{{ __('entries.ed.job_card') }}</h2>
        @if ($chip !== null)
            <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
        @endif
    </div>
    <div class="flex flex-col gap-3 p-4">
        <p class="{{ $hint }}">{{ __('entries.ed.job_hint', ['cat' => $jobCard['category'], 'max' => \App\Modules\Cms\Support\JobFacts::FACTS_MAX, 'chars' => \App\Modules\Cms\Support\JobFacts::FACT_TEXT_MAX]) }}</p>

        {{-- the card as the job list draws it: the title, the label in its corner, the boxes with words --}}
        <div class="flex min-w-0 flex-col gap-2 rounded-md border border-dashed border-border-input bg-surface-muted p-3" role="group" aria-label="{{ __('entries.ed.job_pv_aria') }}" data-job-preview>
            <span class="text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text-muted" aria-hidden="true">{{ __('entries.ed.job_pv_label') }}</span>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[calc(0.875rem+var(--user-text-boost))] leading-[1.4] text-text-strong [overflow-wrap:anywhere]">
                <b x-text="String($wire.title ?? '').trim() || @js(__('entries.ed.job_title_fallback'))">{{ trim($title) !== '' ? trim($title) : __('entries.ed.job_title_fallback') }}</b>
                <span class="inline-flex items-center rounded-full bg-admin-danger-solid px-2 text-[calc(0.75rem+var(--user-text-boost))] font-semibold leading-[1.6] text-white"
                      x-text="badge()" x-bind:hidden="badge() === ''" @if ($jobLen($jobBadge) === 0) hidden @endif>{{ \App\Modules\Admin\Support\Entries\JobCardForm::clean($jobBadge) }}</span>
            </div>
            <ul class="m-0 flex list-none flex-wrap gap-x-2 gap-y-1 p-0" x-bind:hidden="! any()" @if (! $previewAny) hidden @endif>
                @foreach ($jobCard['facts'] as $i => $fact)
                    <li wire:key="job-pv-{{ $fact['key'] }}" data-tone="{{ $fact['accent'] ? 'accent' : 'plain' }}" class="{{ $pill }} {{ $fact['accent'] ? $pillAccent : $pillPlain }}"
                        x-bind:hidden="fact({{ $i }}) === ''" @if ($jobLen($fact['text']) === 0) hidden @endif>
                        @if ($fact['accent'])<x-bs-admin::icon name="ri-star-fill" class="size-3 shrink-0" />@endif<span x-text="fact({{ $i }})">{{ \App\Modules\Admin\Support\Entries\JobCardForm::clean($fact['text']) }}</span>
                    </li>
                @endforeach
                @if ($jobCard['chip'] !== '')
                    <li data-tone="{{ $jobDeadlineAccent ? 'accent' : 'plain' }}" class="{{ $pill }} {{ $jobDeadlineAccent ? $pillAccent : $pillPlain }}">
                        @if ($jobDeadlineAccent)<x-bs-admin::icon name="ri-star-fill" class="size-3 shrink-0" />@endif<span>{{ $jobCard['chip'] }}</span>
                    </li>
                @endif
            </ul>
            <p class="{{ $hint }}" x-bind:hidden="any()" @if ($previewAny) hidden @endif>{{ __('entries.ed.job_pv_none') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text">{{ __('entries.ed.job_facts_h') }}</span>
            <span class="seo-count ms-auto" data-b="{{ $jobCard['full'] ? 'near' : '' }}">{{ $jobCard['usedText'] }}</span>
        </div>
        {{-- the count and, once the four are used, why no box can be added: read out as they change --}}
        <x-bs-admin::sr-live>{{ $jobCard['usedText'] }}@if ($jobCard['full']). {{ __('entries.ed.job_full', ['max' => \App\Modules\Cms\Support\JobFacts::FACTS_MAX]) }}@endif</x-bs-admin::sr-live>
        @error('jobFacts') <x-bs-admin::field-error id="entry-jf-e">{{ $message }}</x-bs-admin::field-error> @enderror

        @foreach ($jobCard['facts'] as $i => $fact)
            @php($factError = $errors->first('jobFacts.'.$i.'.text'))
            <div wire:key="job-fact-{{ $fact['key'] }}" class="flex min-w-0 flex-col gap-1 {{ $i > 0 ? 'border-t border-border pt-3' : '' }}" data-job-fact>
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <label for="entry-jf-{{ $i }}" class="{{ $boxLabel }}">{{ __('entries.ed.job_fact_n', ['n' => $fact['n']]) }}</label>
                    <span id="entry-jf-{{ $i }}-c" class="seo-count ms-auto" data-b="{{ $jobLen($fact['text']) >= 36 ? 'near' : '' }}"
                          x-bind:data-b="len(fact({{ $i }})) >= 36 ? 'near' : ''" x-text="count(@js($jobCount), len(fact({{ $i }})))">{{ str_replace('__N__', (string) $jobLen($fact['text']), $jobCount) }}</span>
                </div>
                <input type="text" id="entry-jf-{{ $i }}" wire:model="jobFacts.{{ $i }}.text" maxlength="{{ \App\Modules\Cms\Support\JobFacts::FACT_TEXT_MAX }}"
                       placeholder="{{ $fact['placeholder'] }}" aria-describedby="entry-jf-{{ $i }}-c{{ $factError !== '' ? ' entry-jf-'.$i.'-e' : '' }}"
                       @if ($factError !== '') aria-invalid="true" @endif
                       x-on:blur="if ($el.value.trim() === '' || $el.getAttribute('aria-invalid') === 'true') { $wire.touchJobFact({{ $i }}) }"
                       class="field {{ $fieldLook }}">
                @if ($factError !== '') <x-bs-admin::field-error id="entry-jf-{{ $i }}-e">{{ $factError }}</x-bs-admin::field-error> @endif
                <div class="flex flex-wrap items-center gap-2">
                    <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="toggleJobAccent({{ $i }})" aria-pressed="{{ $fact['accent'] ? 'true' : 'false' }}"
                                        title="{{ $fact['accent'] ? __('entries.ed.job_accent_on_tip') : __('entries.ed.job_accent_off_tip') }}">
                        <x-bs-admin::icon :name="$fact['accent'] ? 'ri-star-fill' : 'ri-star'" :class="$glyph" />{{ __('entries.ed.job_accent') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="removeJobFact({{ $i }})"
                                        aria-label="{{ __('entries.ed.job_remove_aria', ['name' => __('entries.ed.job_fact_n', ['n' => $fact['n']])]) }}" title="{{ __('entries.ed.job_remove_tip') }}">
                        <x-bs-admin::icon name="ri-close" :class="$glyph" />{{ __('entries.ed.job_remove') }}
                    </x-bs-admin::button>
                </div>
            </div>
        @endforeach

        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('entries.ed.job_add_aria') }}">
            <span class="text-caption text-text-muted">{{ __('entries.ed.job_add') }}</span>
            @foreach ($jobCard['suggestions'] as $suggestion)
                @if ($jobCard['full'])
                    <x-bs-admin::button variant="outline" color="neutral" size="sm" class="{{ $quiet }}" aria-disabled="true" title="{{ $suggestion['tip'] }}"
                                        x-on:click="$dispatch('bs-toast', { text: {{ \Illuminate\Support\Js::from(__('entries.ed.job_full', ['max' => \App\Modules\Cms\Support\JobFacts::FACTS_MAX])) }} })">
                        <x-bs-admin::icon name="ri-add" :class="$glyph" />{{ $suggestion['label'] }}
                    </x-bs-admin::button>
                @else
                    <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="addJobFact('{{ $suggestion['kind'] }}')" title="{{ $suggestion['tip'] }}">
                        <x-bs-admin::icon name="ri-add" :class="$glyph" />{{ $suggestion['label'] }}
                    </x-bs-admin::button>
                @endif
            @endforeach
        </div>
        @if ($jobCard['full'])
            <p class="{{ $hint }}">{{ __('entries.ed.job_full', ['max' => \App\Modules\Cms\Support\JobFacts::FACTS_MAX]) }}</p>
        @endif

        <div class="flex min-w-0 flex-col gap-1.5">
            <label for="entry-job-dl" class="{{ $label }}">{{ __('entries.ed.job_dl_label') }}</label>
            <input type="date" id="entry-job-dl" wire:model.live.change="jobDeadline"
                   @readonly($jobCard['deadlineLocked']) @if ($jobCard['deadlineLocked']) aria-readonly="true" @endif
                   aria-describedby="@error('jobDeadline') entry-job-dl-e @enderror entry-job-dl-h" @error('jobDeadline') aria-invalid="true" @enderror
                   class="field {{ $fieldLook }}">
            @error('jobDeadline') <x-bs-admin::field-error id="entry-job-dl-e">{{ $message }}</x-bs-admin::field-error> @enderror
            <p id="entry-job-dl-h" class="{{ $hint }}">{{ $jobCard['deadlineHint'] }}</p>
            @if ($jobCard['chip'] !== '')
                <div class="flex flex-wrap items-center gap-2">
                    <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="toggleJobDeadlineAccent" aria-pressed="{{ $jobDeadlineAccent ? 'true' : 'false' }}"
                                        title="{{ $jobDeadlineAccent ? __('entries.ed.job_dl_accent_on_tip') : __('entries.ed.job_dl_accent_off_tip') }}">
                        <x-bs-admin::icon :name="$jobDeadlineAccent ? 'ri-star-fill' : 'ri-star'" :class="$glyph" />{{ __('entries.ed.job_accent') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="clearJobDeadline" title="{{ __('entries.ed.job_dl_clear') }}">
                        <x-bs-admin::icon name="ri-close" :class="$glyph" />{{ __('entries.ed.job_dl_clear') }}
                    </x-bs-admin::button>
                </div>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-1.5 border-t border-border pt-3">
            <label for="entry-job-bd" class="{{ $label }}">{{ __('entries.ed.job_badge_label') }}</label>
            <input type="text" id="entry-job-bd" wire:model="jobBadge" maxlength="{{ \App\Modules\Cms\Support\JobFacts::BADGE_MAX }}" placeholder="{{ __('entries.ed.job_badge_ph') }}"
                   aria-describedby="entry-job-bd-h entry-job-bd-c @error('jobBadge') entry-job-bd-e @enderror" @error('jobBadge') aria-invalid="true" @enderror
                   class="field {{ $fieldLook }}">
            @error('jobBadge') <x-bs-admin::field-error id="entry-job-bd-e">{{ $message }}</x-bs-admin::field-error> @enderror
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <p id="entry-job-bd-h" class="{{ $hint }}">{{ __('entries.ed.job_badge_hint') }}</p>
                <span id="entry-job-bd-c" class="seo-count ms-auto" data-b="{{ $jobLen($jobBadge) >= 20 ? 'near' : '' }}"
                      x-bind:data-b="len(badge()) >= 20 ? 'near' : ''" x-text="count(@js($badgeCount), len(badge()))">{{ str_replace('__N__', (string) $jobLen($jobBadge), $badgeCount) }}</span>
            </div>
        </div>
    </div>
</section>
