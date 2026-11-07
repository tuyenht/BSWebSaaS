@php
    use Illuminate\Support\Js;

    // The "Đổi chuyên mục chính" box (one row from its ⋯ menu, or the ticked rows from the bar). The server opens it
    // (catOpen) only for products that are still there; the choice and the search live in the browser until "Đổi chuyên mục".
    // The search ignores diacritics and case; a search result shows each category with the names above it, a plain list indents.
    $dlg = $catDialog;
    $dlgCount = $dlg['count'] ?? 0;
@endphp
<x-bs-admin::modal model="catOpen" size="md" :title="$dlgCount > 1 ? __('catalog.cat_title_many', ['count' => $dlgCount]) : __('catalog.cat_title_one')" :close-label="__('catalog.cat_close')">
    @if ($dlg !== null)
        <div class="flex min-h-0 flex-col gap-3"
             x-data="{
                 q: '',
                 pick: {{ Js::from($dlg['pick']) }},
                 items: {{ Js::from($dlg['choices']) }},
                 deacc(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd'); },
                 get shown() { const w = this.deacc(this.q).trim(); return w === '' ? this.items : this.items.filter((c) => this.deacc(c.label).includes(w)).map((c) => ({ ...c, depth: 0 })); },
                 get searching() { return this.deacc(this.q).trim() !== ''; },
                 go() { if (this.pick) { $wire.changeCategory(this.pick); } },
             }"
             x-effect="open && $nextTick(() => setTimeout(() => $refs.catq && $refs.catq.focus(), 80))">
            <p class="m-0 text-body text-text [overflow-wrap:anywhere]">{{ $dlg['name'] !== null ? '“'.$dlg['name'].'”' : __('catalog.cat_for_many') }}</p>
            <x-bs-admin::search x-ref="catq" :label="__('catalog.cat_search')" :placeholder="__('catalog.cat_search_hint')" x-model="q" @keydown.enter.prevent="go()" />
            <div role="radiogroup" aria-label="{{ __('catalog.cat_group') }}" class="max-h-[min(20rem,50dvh)] overflow-auto rounded border border-border [scrollbar-gutter:stable]">
                <template x-for="c in shown" :key="c.ulid">
                    <button type="button" role="radio" x-bind:aria-checked="(pick === c.ulid).toString()" x-on:click="pick = c.ulid"
                        class="group flex min-h-menu-row w-full items-center gap-3 px-3 py-1.5 text-left text-body hover:bg-surface-hover"
                        x-bind:style="'padding-inline-start:' + (0.75 + c.depth * 1.25) + 'rem'">
                        <span aria-hidden="true" class="inline-flex size-[1.125rem] shrink-0 rounded-full border border-border-input bg-surface group-aria-checked:border-[6px] group-aria-checked:border-primary-text"></span>
                        <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">
                            <span x-show="searching && c.path" class="text-text-muted" x-text="c.path"></span><span x-text="c.label"></span>
                        </span>
                        <span class="shrink-0 text-caption text-text-muted" x-text="Number(c.count).toLocaleString('vi-VN')"></span>
                    </button>
                </template>
                <p x-show="items.length === 0" class="m-0 p-3 text-body text-text-muted">{{ __('catalog.cat_empty') }}</p>
                <p x-show="items.length > 0 && shown.length === 0" x-cloak class="m-0 p-3 text-body text-text-muted" x-text="{{ Js::from(__('catalog.cat_nomatch')) }}.replace(':q', q.trim())"></p>
            </div>
            <p class="m-0 text-caption text-text-muted">{{ __('catalog.cat_note') }}@if ($dlg['moves'] && $dlg['published'] > 0) {{ __('catalog.cat_note_pub', ['count' => $dlg['published']]) }}@endif</p>
            <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-3 max-[599.98px]:*:flex-[1_1_100%]">
                <x-bs-admin::button color="primary" variant="outline" x-on:click="hide()">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" x-bind:aria-disabled="(! pick).toString()" class="aria-disabled:opacity-60" x-on:click="go()">
                    <x-bs-admin::icon name="ri-folder-transfer" class="size-4" /> {{ $dlgCount > 1 ? __('catalog.cat_ok_n', ['count' => $dlgCount]) : __('catalog.cat_ok') }}
                </x-bs-admin::button>
            </div>
        </div>
    @endif
</x-bs-admin::modal>
