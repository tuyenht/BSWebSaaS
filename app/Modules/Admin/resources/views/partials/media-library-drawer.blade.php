{{--
    The media library's file detail drawer (design board 12 «Thư viện ảnh & tệp»), drawn by Library
    from WithMediaDetailDrawer::detailView() as $d. The picture's description and title are edited
    per website language: one field pair per language, the chips choose which one shows; the chips'
    dots, the counter and «Có thay đổi chưa lưu» read the fields as they are typed in (Alpine part
    bsMediaDrawer, resources/js/admin-media-drawer.js). «Lưu» sends every language at once. The unsaved-changes dialog sits outside the panel (the
    panel is a size container) and is asked by every way of closing it (the drawer's `guard`).
--}}
@php
    $m = $d['media'];
    $langs = $d['languages'];
    $multi = count($langs) > 1;
    $few = count($langs) <= 3;
    $names = collect($langs)->mapWithKeys(fn (array $l): array => [$l['code'] => $l['name']])->all();
    $rtl = ['ar', 'he', 'fa', 'ur'];
    $label = 'mb-1.5 flex items-center gap-2 text-body font-medium text-text';
    $field = 'block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder focus:border-primary-text aria-invalid:border-danger-text read-only:bg-surface-muted';
    $hint = 'm-0 mt-1.5 text-small text-text-muted';
    $words = [
        'full' => __('media.drw.st_full'),
        'empty' => __('media.drw.st_empty'),
        'miss' => __('media.drw.st_miss'),
        'src' => __('media.drw.st_source'),
        'tipSrc' => __('media.drw.tip_source'),
        'lineSource' => __('media.drw.line_source'),
        'lineEmpty' => __('media.drw.line_empty'),
        'lineFull' => __('media.drw.line_full'),
        'progress' => __('media.drw.lang_progress'),
        'near' => __('media.drw.alt_near', ['n' => \App\Modules\Media\Models\Media::ALT_RECOMMENDED_LENGTH, 'max' => \App\Modules\Media\Models\Media::ALT_MAX_LENGTH]),
    ];
    $groupIcons = ['product' => 'ri-shopping-bag-3', 'cover' => 'ri-image', 'content' => 'ri-file-text', 'brand' => 'ri-image'];
    $pages = $m->pageCount();
    $pagesN = $pages !== null ? number_format($pages, 0, ',', '.') : null;
    $dims = $m->width !== null && $m->height !== null
        ? __('media.drw.dims_px', ['w' => number_format($m->width, 0, ',', '.'), 'h' => number_format($m->height, 0, ',', '.')])
        : ($pages !== null ? trans_choice('media.lib.pages', $pages, ['n' => $pagesN]) : '—');
    // a PDF not counted (yet) is named «PDF» alone, never given a made-up count
    $fileLabel = $d['pdf'] && $pages !== null ? trans_choice('media.drw.pdf_pages', $pages, ['n' => $pagesN]) : strtoupper($m->extLabel());
@endphp
<div id="media-detail-guard">
    @if ($d['canEdit'])
        <x-bs-admin::unsaved-dialog :count="$d['changes']" :label="$d['name']" save="saveDetail" discard="discardDetail" scope="[data-unsaved-scope=media-detail-guard]"
            :body="__('media.drw.ud_body')" :save-label="__('media.drw.ud_save')" :discard-label="__('media.drw.ud_discard')" />
    @endif
</div>
<x-bs-admin::drawer wide :title="$d['title']" close="closeDetail" :return-to="'mopen-'.$m->ulid" :close-label="__('admin.close')"
    busy="saveDetail" guard="media-detail-guard" wire:key="mdrw-{{ $m->ulid }}">
    @if (! $d['trashed'] || $d['canPurge'])
        <x-slot:actions>
            <x-bs-admin::list.actions head :name="__('media.drw.this_file')" :title="__('media.drw.menu')">
                <x-slot:menu>
                    @if (! $d['trashed'])
                        <x-bs-admin::list.menu-item icon="ri-download-2" :href="$d['download']" download>{{ __('media.drw.download') }}</x-bs-admin::list.menu-item>
                        @if ($d['canUpload'])
                            <x-bs-admin::list.menu-item icon="ri-folder-transfer" wire:click="moveDetail">{{ __('media.drw.move') }}</x-bs-admin::list.menu-item>
                        @endif
                        @if ($d['mayTrash'])
                            <x-bs-admin::list.menu-sep />
                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger wire:click="trashDetail">{{ __('media.drw.trash') }}</x-bs-admin::list.menu-item>
                        @endif
                    @elseif ($d['support'])
                        <x-bs-admin::list.menu-item icon="ri-delete-bin-2" danger :why="__('admin.support.locked')">{{ __('media.drw.purge') }}</x-bs-admin::list.menu-item>
                    @else
                        <x-bs-admin::list.menu-item icon="ri-delete-bin-2" danger wire:click="purgeDetail">{{ __('media.drw.purge') }}</x-bs-admin::list.menu-item>
                    @endif
                </x-slot:menu>
            </x-bs-admin::list.actions>
        </x-slot:actions>
    @endif

    <div class="flex flex-col gap-4" x-ignore wire:ignore.self data-bs-lazy="bsMediaDrawer"
         x-data="bsMediaDrawer(@js(['source' => $d['source'], 'names' => $names, 'few' => $few, 'near' => \App\Modules\Media\Models\Media::ALT_RECOMMENDED_LENGTH, 'w' => $words]))"
         x-on:input="scan()" x-on:media-detail-saved.window="saved($event)">
        <div class="flex h-[min(16.25rem,40dvh)] items-center justify-center overflow-hidden rounded-md border border-border bg-surface-muted p-3">
            @if ($d['image'])
                <img src="{{ $d['preview'] }}" @if ($d['srcset']) srcset="{{ $d['srcset'] }}" sizes="(min-width: 600px) 30rem, 100vw" @endif
                     alt="{{ ($m->alt[$d['source']] ?? '') !== '' ? $m->alt[$d['source']] : $d['name'] }}"
                     @if ($m->width) width="{{ $m->width }}" height="{{ $m->height }}" @endif decoding="async"
                     class="h-auto max-h-full w-auto max-w-full object-contain">
            @else
                <span class="flex flex-col items-center gap-2 text-body font-semibold text-text-muted">
                    <x-bs-admin::icon :name="$d['pdf'] ? 'ri-file-pdf-2' : 'ri-file'" class="size-16" />{{ $fileLabel }}
                </span>
            @endif
        </div>
        <p class="m-0 text-body font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $d['name'] }}</p>
        @if ($d['trashed'])
            <x-bs-admin::alert color="warning" role="note" class="m-0">{{ __('media.drw.trash_note', ['n' => $d['left']]) }}</x-bs-admin::alert>
        @endif

        @if ($d['image'])
            @if ($multi)
                <div class="flex flex-col gap-2">
                    <div class="relative flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface px-2 py-1.5" role="group" aria-label="{{ __('media.drw.langs') }}"
                         x-on:click.outside="open = false"
                         x-on:keydown.escape="esc($event)">
                        <span class="flex px-0.5 text-text-muted" title="{{ __('media.drw.langs_icon') }}"><x-bs-admin::icon name="ri-translate-2" class="size-[calc(1.125rem+var(--user-text-boost))]" /></span>
                        @foreach ($langs as $l)
                            <button type="button" wire:key="mdrw-pill-{{ $l['code'] }}" x-show="shown(@js($l['code']))" @if (! $few && ! $l['source']) x-cloak @endif x-on:click="pick(@js($l['code']), false)"
                                    x-bind:aria-pressed="(lang === @js($l['code'])).toString()" x-bind:title="tip(@js($l['code']))"
                                    class="inline-flex min-h-control items-center gap-1.5 rounded-full border border-border-strong bg-surface px-3 text-small whitespace-nowrap text-text hover:bg-surface-hover aria-pressed:border-primary-text aria-pressed:bg-primary/10 aria-pressed:font-semibold aria-pressed:text-primary-text">
                                <span class="lb-dot" x-bind:data-k="dot(@js($l['code']))" aria-hidden="true"></span>
                                <span>{{ $l['name'] }}</span>
                                @if ($l['source'])
                                    <span class="lb-star" role="img" aria-label="{{ __('media.drw.source_lang') }}"><x-bs-admin::icon name="ri-star-fill" class="size-[calc(0.8125rem+var(--user-text-boost))]" /></span>
                                @endif
                            </button>
                        @endforeach
                        @if (! $few)
                            <button type="button" x-ref="more" x-on:click="toggle()"
                                    aria-haspopup="dialog" x-bind:aria-expanded="open.toString()" aria-controls="mdrw-lpop" aria-label="{{ __('media.drw.lang_more') }}"
                                    class="inline-flex min-h-control items-center gap-1 rounded-full border border-border-strong bg-surface-muted px-3 text-small font-semibold text-text hover:bg-surface-hover">
                                <span x-text="'+' + more()"></span><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" />
                            </button>
                            <div id="mdrw-lpop" x-ref="pop" x-show="open" x-cloak role="dialog" aria-label="{{ __('media.drw.lang_pick') }}"
                                 class="absolute start-0 top-[calc(100%+0.375rem)] z-30 flex w-[min(16.25rem,calc(100cqw-1rem))] flex-col rounded-md border border-border-strong bg-surface p-1.5 shadow-lg">
                                <p class="m-0 mx-2 mt-0.5 mb-1.5 text-caption text-text-muted" x-text="progress()"></p>
                                @if (count($langs) >= 6)
                                    <label class="mx-1 mb-1.5 flex min-h-control items-center gap-2 rounded border border-border-input px-2.5">
                                        <x-bs-admin::icon name="ri-search" class="size-4 text-text-muted" />
                                        <input type="search" x-model="q" aria-label="{{ __('media.drw.lang_search') }}" placeholder="{{ __('media.drw.lang_search_ph') }}" class="min-w-0 flex-1 border-0 bg-transparent text-body text-text placeholder:text-placeholder">
                                    </label>
                                @endif
                                @foreach ($langs as $l)
                                    <button type="button" x-show="match(@js($l['code']))" x-on:click="pick(@js($l['code']), true)" x-bind:aria-current="(lang === @js($l['code'])).toString()"
                                            class="grid min-h-hit w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 rounded px-2.5 py-1.5 text-start text-text hover:bg-surface-hover aria-[current=true]:bg-primary/8">
                                        <span class="lb-dot" x-bind:data-k="dot(@js($l['code']))" aria-hidden="true"></span>
                                        <span class="min-w-0"><b class="block font-semibold">{{ $l['name'] }}</b><small class="block text-caption text-text-muted" x-text="sub(@js($l['code']))"></small></span>
                                        @if ($l['source'])
                                            <span class="lb-star" role="img" aria-label="{{ __('media.drw.source_lang') }}"><x-bs-admin::icon name="ri-star-fill" class="size-[calc(0.8125rem+var(--user-text-boost))]" /></span>
                                        @endif
                                    </button>
                                @endforeach
                                <p class="m-0 px-2.5 py-2 text-small text-text-muted" x-show="! anyMatch()" x-cloak>{{ __('admin.lb.no_result') }}</p>
                                @if ($d['manage'])
                                    <div class="my-1 h-px bg-border" role="separator"></div>
                                    <a href="{{ $d['manage'] }}" wire:navigate class="flex min-h-control items-center gap-2.5 rounded px-2.5 text-small text-text hover:bg-surface-hover">
                                        <x-bs-admin::icon name="ri-settings-3" class="size-4 text-text-muted" />{{ __('admin.lb.manage') }}
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                    <p class="m-0 flex items-start gap-2 text-small" x-bind:class="tone() === 'warn' ? 'text-warning-text' : 'text-text-muted'">
                        <span x-show="tone() !== 'warn'"><x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" /></span>
                        <span x-show="tone() === 'warn'" x-cloak><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /></span>
                        <span x-text="line()">{{ $words['lineSource'] }}</span>
                    </p>
                </div>
            @endif

            @foreach ($langs as $l)
                @php
                    $code = $l['code'];
                    $altErr = (string) $errors->first('detailAlt.'.$code);
                    $titleErr = (string) $errors->first('detailTitle.'.$code);
                    $dir = in_array($code, $rtl, true) ? 'rtl' : 'ltr';
                @endphp
                <div wire:key="mdrw-f-{{ $code }}" class="flex flex-col gap-4" x-show="lang === @js($code)" @if (! $loop->first) x-cloak @endif>
                    <div class="flex min-w-0 flex-col">
                        <label for="mdrw-alt-{{ $code }}" class="{{ $label }}">
                            {{ __('media.drw.alt') }}
                            @if ($multi)<span class="rounded bg-surface-muted px-1.5 text-caption font-semibold uppercase text-text-muted">{{ $code }}</span>@endif
                            <span class="ms-auto text-caption font-medium tabular-nums text-text-muted" x-bind:class="count > near && 'font-semibold text-danger-text'" x-text="count + '/{{ \App\Modules\Media\Models\Media::ALT_MAX_LENGTH }}'"></span>
                        </label>
                        <textarea id="mdrw-alt-{{ $code }}" rows="3" maxlength="{{ \App\Modules\Media\Models\Media::ALT_MAX_LENGTH }}" dir="{{ $dir }}" lang="{{ $code }}"
                                  wire:model="detailAlt.{{ $code }}" data-lang="{{ $code }}" data-orig="{{ $d['base']['alt'][$code] ?? '' }}"
                                  placeholder="{{ $l['source'] ? __('media.drw.alt_ph') : $l['name'] }}"
                                  aria-describedby="mdrw-alt-{{ $code }}-h{{ $altErr !== '' ? ' mdrw-alt-'.$code.'-e' : '' }}" @if ($altErr !== '') aria-invalid="true" @endif
                                  @if (! $d['canEdit']) readonly @endif
                                  class="{{ $field }} py-2 text-body">{{ is_string($detailAlt[$code] ?? null) ? $detailAlt[$code] : '' }}</textarea>
                        @if ($altErr !== '')
                            <x-bs-admin::field-error id="mdrw-alt-{{ $code }}-e" class="mt-1.5">{{ $altErr }}</x-bs-admin::field-error>
                        @endif
                        <p id="mdrw-alt-{{ $code }}-h" class="{{ $hint }}">{{ __('media.drw.alt_hint') }}</p>
                        {{-- said once when the description passes the recommended length, not on every key --}}
                        <p class="sr-only" role="status" x-text="count > near ? w.near : ''"></p>
                    </div>
                    <div class="flex min-w-0 flex-col">
                        <label for="mdrw-title-{{ $code }}" class="{{ $label }}">
                            {{ __('media.drw.title') }}
                            @if ($multi)<span class="rounded bg-surface-muted px-1.5 text-caption font-semibold uppercase text-text-muted">{{ $code }}</span>@endif
                        </label>
                        <input id="mdrw-title-{{ $code }}" type="text" maxlength="{{ \App\Modules\Media\Models\Media::TITLE_MAX_LENGTH }}" dir="{{ $dir }}" lang="{{ $code }}"
                               wire:model="detailTitle.{{ $code }}" value="{{ is_string($detailTitle[$code] ?? null) ? $detailTitle[$code] : '' }}" data-orig="{{ $d['base']['title'][$code] ?? '' }}"
                               placeholder="{{ $l['source'] ? __('media.drw.title_ph') : $l['name'] }}"
                               aria-describedby="mdrw-title-{{ $code }}-h{{ $titleErr !== '' ? ' mdrw-title-'.$code.'-e' : '' }}" @if ($titleErr !== '') aria-invalid="true" @endif
                               @if (! $d['canEdit']) readonly @endif
                               class="field {{ $field }}">
                        @if ($titleErr !== '')
                            <x-bs-admin::field-error id="mdrw-title-{{ $code }}-e" class="mt-1.5">{{ $titleErr }}</x-bs-admin::field-error>
                        @endif
                        <p id="mdrw-title-{{ $code }}-h" class="{{ $hint }}">{{ __('media.drw.title_hint') }}</p>
                    </div>
                </div>
            @endforeach
        @endif

        <div class="flex min-w-0 flex-col">
            <label for="mdrw-url" class="{{ $label }}">{{ __('media.drw.url') }}</label>
            <div class="flex min-h-control items-center gap-1.5 rounded border border-border-input bg-surface-muted ps-3 pe-1">
                <input id="mdrw-url" x-ref="url" type="text" readonly value="{{ $d['fileUrl'] }}" dir="ltr" x-on:focus="$el.select()" aria-describedby="mdrw-url-h"
                       class="min-w-0 flex-1 self-stretch border-0 bg-transparent p-0 font-mono text-body text-text-strong">
                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="copy()">
                    <span x-show="! copied" class="inline-flex"><x-bs-admin::icon name="ri-file-copy" class="size-4" /></span>
                    <span x-show="copied" x-cloak class="inline-flex"><x-bs-admin::icon name="ri-check" class="size-4" /></span>
                    <span x-text="copied ? @js(__('media.drw.copied_btn')) : @js(__('admin.copy'))">{{ __('admin.copy') }}</span>
                </x-bs-admin::button>
            </div>
            <p id="mdrw-url-h" class="{{ $hint }}">{{ __('media.drw.url_hint') }}</p>
        </div>

        <dl class="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-body">
            <dt class="text-text-muted">{{ __('media.drw.dims') }}</dt><dd class="m-0 text-end text-text-strong [overflow-wrap:anywhere]">{{ $dims }}</dd>
            <dt class="text-text-muted">{{ __('media.drw.size') }}</dt><dd class="m-0 text-end text-text-strong">{{ \App\Modules\Shared\Support\Bytes::size((int) $m->size) }}</dd>
            <dt class="text-text-muted">{{ __('media.drw.format') }}</dt><dd class="m-0 text-end text-text-strong">{{ strtoupper($m->extLabel()) }}</dd>
            <dt class="text-text-muted">{{ __('media.drw.folder') }}</dt><dd class="m-0 text-end text-text-strong [overflow-wrap:anywhere]">{{ $m->folder !== null ? $m->folder->name : __('media.lib.unfiled') }}</dd>
            <dt class="text-text-muted">{{ __('media.drw.by') }}</dt><dd class="m-0 text-end text-text-strong [overflow-wrap:anywhere]">{{ $d['uploader'] !== '' ? $d['uploader'] : '—' }}</dd>
            <dt class="text-text-muted">{{ __('media.drw.at') }}</dt><dd class="m-0 text-end text-text-strong">{{ $m->created_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}</dd>
        </dl>

        <section class="flex flex-col gap-2 border-t border-border pt-3" aria-labelledby="mdrw-use">
            <h3 id="mdrw-use" class="m-0 flex items-center gap-2 text-body font-semibold text-text-strong">
                <x-bs-admin::icon name="ri-links" class="size-4 text-text-muted" />{{ __($d['trashed'] ? 'media.drw.was_used' : 'media.drw.used', ['n' => $d['useN']]) }}
            </h3>
            @if ($d['useN'] === 0)
                <p class="m-0 text-small text-text-muted">{{ __('media.drw.unused') }}</p>
            @endif
            @foreach ($d['groups'] as $g => $rows)
                <div class="flex flex-col gap-1" wire:key="mdrw-g-{{ $g }}">
                    <span class="text-caption font-semibold text-text-muted">{{ __('media.drw.g_'.$g) }} · {{ count($rows) }}</span>
                    @foreach ($rows as $u)
                        @php $row = 'flex min-h-control w-full items-center gap-2 rounded border border-border bg-surface px-2 py-1.5 text-body text-text-strong'; @endphp
                        @if ($u['url'] !== null)
                            <a href="{{ $u['url'] }}" wire:navigate class="{{ $row }} hover:bg-surface-hover">
                                <x-bs-admin::icon :name="$groupIcons[$g]" class="size-4 shrink-0 text-text-muted" />
                                <span class="min-w-0 flex-1 truncate">{{ $u['label'] }}</span>
                                @if (($u['sub'] ?? null) !== null)<span class="shrink-0 text-caption text-text-muted">{{ $u['sub'] }}</span>@endif
                                <x-bs-admin::icon name="ri-arrow-right-s" class="size-4 shrink-0 text-text-muted" />
                            </a>
                        @else
                            <div class="{{ $row }}">
                                <x-bs-admin::icon :name="$groupIcons[$g]" class="size-4 shrink-0 text-text-muted" />
                                <span class="min-w-0 flex-1 truncate">{{ $u['label'] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endforeach
            <p class="m-0 flex items-start gap-1.5 text-small text-text-muted"><x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" /><span>{{ __('media.drw.inline_note') }}</span></p>
        </section>
    </div>

    <x-slot:footer>
        <div class="flex w-full flex-wrap items-center justify-end gap-2" x-data="{ n: {{ (int) $d['changes'] }} }" x-on:mdrw-dirty.window="n = $event.detail">
            @if ($d['why'] !== '')
                <p class="m-0 basis-full text-small text-text-muted" role="note">{{ $d['why'] }}</p>
            @endif
            @if ($d['trashed'])
                @if ($d['mayRestore'])
                    <x-bs-admin::button color="primary" wire:click="restoreDetail" wire:loading.attr="aria-busy" wire:target="restoreDetail">
                        <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ __('media.drw.restore') }}
                    </x-bs-admin::button>
                @endif
            @else
                <span role="status" aria-live="polite" class="me-auto self-center text-small text-text-muted">
                    <span wire:loading wire:target="saveDetail">{{ __('media.drw.saving') }}</span>
                    <span wire:loading.remove wire:target="saveDetail">
                        <span x-show="n > 0" x-cloak>{{ __('media.drw.dirty') }}</span>
                        @if ($detailSaved)
                            <span x-show="n === 0">{{ __('media.drw.saved') }}</span>
                        @endif
                    </span>
                </span>
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="hide()">{{ __('admin.close') }}</x-bs-admin::button>
                @if ($d['canEdit'])
                    <x-bs-admin::button color="primary" x-bind:aria-disabled="(n === 0).toString()" class="aria-disabled:opacity-60" x-on:click="n > 0 && $wire.saveDetail()">
                        <span wire:loading.remove wire:target="saveDetail" class="inline-flex"><x-bs-admin::icon name="ri-save-3" class="size-4" /></span>
                        <span wire:loading wire:target="saveDetail" class="inline-flex"><x-bs-admin::icon name="ri-loader-4" class="size-4 animate-spin motion-reduce:animate-none" /></span>
                        <span wire:loading.remove wire:target="saveDetail">{{ __('media.drw.save') }}</span>
                        <span wire:loading wire:target="saveDetail">{{ __('media.drw.saving') }}</span>
                    </x-bs-admin::button>
                @endif
            @endif
        </div>
    </x-slot:footer>
</x-bs-admin::drawer>
