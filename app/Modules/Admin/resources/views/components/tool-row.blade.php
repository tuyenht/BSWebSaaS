@php
    // The one-line tools row of a record list below 900 (canvas .ql-tools, K69): [Chọn tất cả] … [Lọc (n)] [Sắp xếp].
    // It never wraps and never scrolls sideways: when the buttons do not fit, the Sort button drops its word first
    // (data-fit="1"), then the Filter button (data-fit="2"), each keeping its icon, tooltip, accessible name and the
    // Filter count badge; only then may the select-all label wrap (data-fit="3"). The words are the ones marked
    // [data-sort-text] and [data-lf-text]; the rules live in resources/css/admin-list.css (.list-tools-row).
    // A host may let the row wrap from some width up (it then goes down as one group beside a wider row), or make it
    // `display: contents` (its items join that row); it is not measured then. A wrapping row says whether it went
    // below the item before it (data-below="1"), so that item (the search) can take the whole line, as on the canvas. A Livewire re-render puts the server HTML back, so the row measures again after each one.
@endphp
<div {{ $attributes->merge(['class' => 'list-tools-row']) }} wire:ignore.self
     x-data="{
         stopMorph: null,
         ro: null,
         fit() {
             const row = this.$root;
             row.dataset.fit = '0';
             const css = getComputedStyle(row);
             if (css.display === 'contents') return;
             if (css.flexWrap === 'wrap') {
                 const prev = row.previousElementSibling;
                 row.dataset.below = prev && row.getBoundingClientRect().top >= prev.getBoundingClientRect().bottom - 1 ? '1' : '0';
                 return;
             }
             delete row.dataset.below;
             for (const step of ['1', '2', '3']) {
                 if (row.scrollWidth <= row.clientWidth) return;
                 row.dataset.fit = step;
             }
         },
         // the row and each item: a larger text size widens the buttons without changing the row's own size
         watch() { this.ro ??= new ResizeObserver(() => this.fit()); this.ro.disconnect(); [this.$root, ...this.$root.children].forEach((el) => this.ro.observe(el)); },
         destroy() { this.stopMorph?.(); this.ro?.disconnect(); },
     }"
     x-init="$nextTick(() => { watch(); fit(); }); try { stopMorph = $wire.interceptMessage(({ onSuccess }) => onSuccess(({ onMorphed }) => onMorphed(() => { watch(); fit(); }))); } catch (e) {}">
    {{ $slot }}
</div>
