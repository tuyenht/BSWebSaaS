{{-- «Chèn khối dùng chung» of «Trình dựng» (board 27), opened by «Khối dùng chung» at the head of
     «+ Thêm khối» (bs-ref-pick). The list is read when the dialog opens (PlacesSharedBlocks::
     sharedBlockChoices), grouped by region, published blocks before drafts; the search runs over
     what was read, by name or code, without diacritics. A row places its block at the end of this
     language's layout (insertSharedBlock), then the focus goes to the placed block. When the layout
     already holds the most shared blocks, every row says so instead of «Chèn». The host gives
     $sharedPicker (the library link, or null) and $localeLabel. --}}
@php
    $refOrder = ['inline', 'header', 'footer', 'sidebar', 'menu'];
    $refIcons = ['footer' => 'ri-layout-bottom', 'header' => 'ri-layout-top', 'sidebar' => 'ri-layout-right', 'inline' => 'ri-insert-column-right', 'menu' => 'ri-node-tree'];
    $refTints = ['footer' => 'bg-primary-soft text-primary-text', 'header' => 'bg-success-soft text-success-text', 'sidebar' => 'bg-info-soft text-info-text', 'inline' => 'bg-warning-soft text-warning-text', 'menu' => 'bg-surface-muted text-text-muted'];
    $refLabels = [];
    foreach ($refOrder as $region) {
        $refLabels[$region] = __('blocks.region.'.$region);
    }
    $refLibrary = $sharedPicker['library'] ?? null;
@endphp
<div x-data="{
        rp: false, mode: 'loading', rows: [], cap: false, q: '', busy: false,
        order: @js($refOrder), labels: @js($refLabels), tints: @js($refTints),
        fold(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').trim(); },
        open() { this.q = ''; this.rows = []; this.cap = false; this.rp = true; this.load(); },
        async load() {
            this.mode = 'loading';
            try {
                const r = await $wire.sharedBlockChoices();
                if (! r || r.ok !== true) { throw new Error('refused'); }
                this.rows = r.rows; this.cap = r.cap; this.mode = 'list';
            } catch (e) { this.mode = 'error'; }
            this.$nextTick(() => { const el = document.getElementById(this.mode === 'error' ? 'ref-pick-retry' : (this.rows.length ? 'ref-pick-q' : 'ref-pick-cancel')); if (el) { el.focus(); } });
        },
        get hits() { const q = this.fold(this.q); return q === '' ? this.rows : this.rows.filter((r) => this.fold(r.name).includes(q) || this.fold(r.key).includes(q)); },
        get groups() { const hits = this.hits; return this.order.map((k) => { const items = hits.filter((r) => r.region === k); return { k, label: this.labels[k] + ' · ' + items.length, items }; }).filter((g) => g.items.length > 0); },
        clearQ() { this.q = ''; this.$nextTick(() => document.getElementById('ref-pick-q')?.focus()); },
        async insert(r) {
            if (this.cap || this.busy) { return; }
            this.busy = true;
            let at = null;
            try { at = await $wire.insertSharedBlock(r.ulid); } catch (e) { at = null; } finally { this.busy = false; }
            this.rp = false;
            if (typeof at === 'number') {
                window.dispatchEvent(new CustomEvent('bs-ref-placed'));
                requestAnimationFrame(() => requestAnimationFrame(() => { const el = document.getElementById('block-ref-' + at); if (el) { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'center' }); } }));
            }
        },
     }" x-on:bs-ref-pick.window="open()" data-ref-picker>
    <x-bs-admin::modal state="rp" close="rp = false" :title="__('entries.ref.title')" :subtitle="__('entries.ref.sub', ['lang' => $localeLabel])" :close-label="__('entries.ref.close')">
        <div class="sticky -top-4 z-1 -mt-4 bg-surface pt-4" x-show="mode === 'loading' || rows.length > 0">
            <x-bs-admin::search id="ref-pick-q" :label="__('entries.ref.search_label')" :placeholder="__('entries.ref.search_ph')" x-model="q" x-bind:disabled="mode === 'loading'" />
        </div>
        <div class="flex flex-col gap-2" x-bind:aria-busy="mode === 'loading' ? 'true' : 'false'">
            <template x-if="mode === 'loading'">
                <div class="flex flex-col gap-2">
                    <p class="m-0 text-small text-text-muted" aria-hidden="true">{{ __('entries.ref.loading') }}</p>
                    <x-bs-admin::sr-live>{{ __('entries.ref.loading') }}</x-bs-admin::sr-live>
                    @for ($k = 0; $k < 3; $k++)
                        <div class="h-14 shrink-0 animate-pulse rounded-[0.625rem] bg-surface-hover motion-reduce:animate-none" aria-hidden="true"></div>
                    @endfor
                </div>
            </template>
            <template x-if="mode === 'error'">
                <x-bs-admin::alert color="danger" role="alert" icon="ri-wifi-off" data-ref-error>
                    <b class="block">{{ __('entries.ref.err_title') }}</b>
                    <span class="block text-small">{{ __('entries.ref.err_how') }}</span>
                    <x-bs-admin::button id="ref-pick-retry" variant="outline" color="secondary" size="sm" class="mt-2" x-on:click="load()">
                        <x-bs-admin::icon name="ri-refresh" class="btn-act__btn-ic" />{{ __('entries.ref.retry') }}
                    </x-bs-admin::button>
                </x-bs-admin::alert>
            </template>
            <template x-if="mode === 'list' && rows.length === 0">
                <div class="btn-act__docs-empty">
                    <b>{{ __('entries.ref.empty_title') }}</b>
                    <span>{{ __('entries.ref.empty_how') }}</span>
                    @if ($refLibrary)
                        <a class="btn-act__lib" href="{{ $refLibrary }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-stack" class="btn-act__btn-ic" />{{ __('entries.ref.library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
                    @endif
                </div>
            </template>
            <template x-if="mode === 'list' && rows.length > 0 && cap">
                <x-bs-admin::alert color="warning" role="status" icon="ri-error-warning" data-ref-cap>{{ __('entries.ref.cap', ['max' => \App\Modules\Theme\Components\BlockReference::LIST_MAX]) }}</x-bs-admin::alert>
            </template>
            <template x-if="mode === 'list' && rows.length > 0 && groups.length === 0">
                <div class="btn-act__docs-empty">
                    <b x-text="@js(__('entries.ref.none_title')).replace(':q', q.trim())"></b>
                    <span>{{ __('entries.ref.none_how') }}</span>
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" class="mt-1" x-on:click="clearQ()">{{ __('entries.ref.clear_q') }}</x-bs-admin::button>
                </div>
            </template>
            <template x-if="mode === 'list'">
                <div class="flex flex-col gap-2">
                    <template x-for="g in groups" :key="g.k">
                        <div class="flex flex-col gap-2">
                            <h3 class="ref-pick__gh" x-text="g.label"></h3>
                            <template x-for="r in g.items" :key="r.ulid">
                                <button type="button" class="btn-act__doc ref-pick__row" x-bind:aria-disabled="cap ? 'true' : 'false'" x-bind:data-ref-row="r.key" x-on:click="insert(r)">
                                    <span class="btn-act__ic ref-pick__ic" x-bind:class="tints[r.region]" aria-hidden="true">
                                        @foreach ($refIcons as $region => $icon)
                                            <x-bs-admin::icon :name="$icon" class="btn-act__ic-glyph" x-show="r.region === '{{ $region }}'" />
                                        @endforeach
                                    </span>
                                    <span class="btn-act__kt">
                                        <span class="btn-act__name"><span x-text="r.name"></span>
                                            <template x-if="r.draft"><x-bs-admin::status-pill status="draft" class="ms-2 align-middle">{{ __('blocks.tab_draft') }}</x-bs-admin::status-pill></template>
                                        </span>
                                        <span class="btn-act__meta" x-text="r.meta"></span>
                                        <template x-if="r.draft">
                                            <span class="ref-pick__line ref-pick__line--warn"><x-bs-admin::icon name="ri-eye-off" class="btn-act__btn-ic" />{{ __('entries.ref.draft_line') }}</span>
                                        </template>
                                        <template x-if="r.onPage">
                                            <span class="btn-act__doc-cur"><x-bs-admin::icon name="ri-check" class="btn-act__btn-ic" />{{ __('entries.ref.on_page') }}</span>
                                        </template>
                                        <template x-if="r.loc">
                                            <span class="ref-pick__line ref-pick__line--warn"><x-bs-admin::icon name="ri-translate-2" class="btn-act__btn-ic" /><span x-text="r.loc"></span></span>
                                        </template>
                                    </span>
                                    <span class="ref-pick__go" aria-hidden="true" x-text="cap ? @js(__('entries.ref.go_cap', ['max' => \App\Modules\Theme\Components\BlockReference::LIST_MAX])) : @js(__('entries.ref.go'))"></span>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
        <x-slot:footer>
            @if ($refLibrary)
                <a class="btn-act__lib me-auto max-[599.98px]:w-auto! max-[599.98px]:self-start" href="{{ $refLibrary }}" target="_blank" rel="noopener" x-show="mode === 'list' && rows.length > 0"><x-bs-admin::icon name="ri-stack" class="btn-act__btn-ic" />{{ __('entries.ref.library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
            @endif
            <x-bs-admin::button id="ref-pick-cancel" variant="outline" color="secondary" x-on:click="rp = false">{{ __('admin.cancel') }}</x-bs-admin::button>
        </x-slot:footer>
    </x-bs-admin::modal>
</div>
