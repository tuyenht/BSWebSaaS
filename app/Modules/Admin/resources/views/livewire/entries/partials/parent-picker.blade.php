{{-- «Thuộc tính trang» › «Trang cha» (design 07 «Trình sửa trang»; pages only, never on the Home page). A button
     that opens a list of the website's pages in website order, indented by level (Editor::parentChoices, read
     each time it opens): «Không có (trang gốc)» first; a page that cannot be the parent stays, greyed
     (aria-disabled), with its reason on a line under it, and a tap on it says the reason. A pick goes to the
     server at once (updatedParentUlid), which checks it and puts a refused one back with the reason under the
     field; the save moves the page. Arrows, Home and End walk the list, Enter picks, Esc closes; the focus
     comes back to the button. The parent is shared by every language: in another language the button is
     locked and says where to change it.
     Needs: $parentName, $parentUlid, $sharedLock, $sharedWhy, $label, $error, $glyph. --}}
@php
    use Illuminate\Support\Js;

    $shown = $parentName !== '' ? $parentName : __('entries.ed.par_none');
    $lockMark = \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-lock" class="size-4" />');
@endphp
<div x-data="{
        open: false, busy: false, failed: false, opts: [], more: false, pick: null,
        locked: {{ Js::from($sharedLock) }},
        async toggle() {
            if (this.locked) { this.$bsToast({{ Js::from(['text' => $sharedWhy, 'icon' => $lockMark, 'stay' => true, 'close' => __('admin.close')]) }}); return; }
            if (this.open) { this.close(true); return; }
            this.open = true; this.busy = true; this.failed = false;
            try { const r = await this.$wire.parentChoices(); this.opts = r ? r.opts : []; this.more = !! (r && r.more); }
            catch (e) { this.failed = true; this.opts = []; }
            this.busy = false;
            this.$nextTick(() => { this.reveal(); this.focusAt(this.items().findIndex((b) => b.getAttribute('aria-selected') === 'true')); });
        },
        // the open list sits whole between the editor's top bar and its bottom save bar (phones), the button kept in view
        reveal() {
            const r = this.$refs.panel.getBoundingClientRect(), bar = document.querySelector('.ped-bbar'), head = document.querySelector('.ped-bar');
            const b = bar ? bar.getBoundingClientRect() : null, h = head ? head.getBoundingClientRect() : null;
            const bottom = (b && b.height > 0 && b.top < innerHeight ? b.top : innerHeight) - 8, top = (h && h.height > 0 ? Math.max(0, h.bottom) : 0) + 8;
            let dy = r.bottom > bottom ? r.bottom - bottom : 0;
            const at = this.$refs.btn.getBoundingClientRect().top;
            if (at - dy < top) dy = at - top;
            if (dy > 0) window.scrollBy(0, dy);
        },
        close(back) { this.open = false; if (back) this.$nextTick(() => this.$refs.btn.focus()); },
        items() { return this.$refs.list ? [...this.$refs.list.querySelectorAll('[role=option]')] : []; },
        focusAt(i) { const L = this.items(); if (! L.length) return; const t = L[Math.max(0, Math.min(i, L.length - 1))]; L.forEach((b) => b.setAttribute('tabindex', b === t ? '0' : '-1')); t.focus({ preventScroll: true });
            const box = this.$refs.list.getBoundingClientRect(), at = t.getBoundingClientRect();
            if (at.top < box.top) this.$refs.list.scrollTop += at.top - box.top; else if (at.bottom > box.bottom) this.$refs.list.scrollTop += at.bottom - box.bottom; },
        key(e) {
            const L = this.items(), at = L.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); this.focusAt(at + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.focusAt(at - 1); }
            else if (e.key === 'Home') { e.preventDefault(); this.focusAt(0); }
            else if (e.key === 'End') { e.preventDefault(); this.focusAt(L.length - 1); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(true); }
            else if (e.key === 'Tab') { this.close(false); }
        },
        choose(o) {
            if (o.why) { this.$bsToast({ text: o.name + ' — ' + o.why, icon: {{ Js::from($lockMark) }}, stay: true, close: {{ Js::from(__('admin.close')) }} }); return; }
            this.close(true);
            if (o.k === this.$wire.parentUlid) return;
            this.pick = o.name;
            this.$wire.set('parentUlid', o.k).finally(() => { this.pick = null; });
        },
     }" x-on:click.outside="open && close(false)" x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true); }">
    <span id="entry-parent-l" class="{{ $label }} mb-1.5">{{ __('entries.ed.par_label') }}</span>
    <div class="relative">
        <button type="button" x-ref="btn" data-parent-btn aria-haspopup="listbox" x-bind:aria-expanded="open ? 'true' : 'false'" aria-labelledby="entry-parent-l entry-parent-v"
                @if ($sharedLock) aria-disabled="true" @endif
                @error('parentUlid') aria-invalid="true" aria-describedby="entry-parent-e" @enderror
                x-on:click="toggle()" x-on:keydown.arrow-down.prevent="open || toggle()"
                class="field flex w-full items-center gap-2 rounded-md border border-border-input bg-surface-muted px-3 text-start aria-expanded:bg-surface text-text transition-colors focus:border-primary-text aria-expanded:border-primary-text aria-invalid:border-danger-text aria-disabled:cursor-not-allowed aria-disabled:opacity-60">
            <x-bs-admin::icon name="ri-node-tree" :class="$glyph.' text-text-muted'" />
            <span id="entry-parent-v" class="min-w-0 flex-1 truncate">
                <span x-show="pick === null">{{ $shown }}</span><span x-show="pick !== null" x-cloak x-text="pick"></span>
            </span>
            <x-bs-admin::icon name="ri-arrow-down-s" :class="$glyph.' text-text-muted transition-transform'" x-bind:class="open && 'rotate-180'" />
        </button>
        <div wire:ignore x-ref="panel" x-show="open" x-cloak class="absolute inset-x-0 top-[calc(100%+6px)] z-30 rounded-md border border-border bg-surface py-1 shadow-card">
            <p x-show="busy" class="m-0 px-3 py-2 text-small text-text-muted">{{ __('entries.mv_loading') }}</p>
            <p x-show="failed" class="m-0 px-3 py-2 text-small text-danger-text">{{ __('entries.ed.par_failed') }}</p>
            <div x-ref="list" role="listbox" aria-labelledby="entry-parent-l" x-show="! busy && ! failed" x-on:keydown="key($event)"
                 class="max-h-[min(420px,55vh)] overflow-y-auto overscroll-contain">
                <template x-for="o in opts" :key="o.k ?? ''">
                    <button type="button" role="option" class="entry-pi flex min-h-menu-row w-full flex-wrap items-center gap-x-2 gap-y-0.5 border-0 bg-transparent py-2 pe-3 any-pointer-coarse:min-h-menu-row-touch text-start text-body text-text hover:bg-surface-hover focus-visible:bg-surface-hover aria-disabled:cursor-not-allowed aria-disabled:text-text-muted aria-selected:bg-primary-soft aria-selected:font-semibold aria-selected:text-primary-text"
                            x-bind:aria-selected="o.k === $wire.parentUlid ? 'true' : 'false'" x-bind:aria-disabled="o.why ? 'true' : null"
                            x-bind:tabindex="o.k === $wire.parentUlid ? '0' : '-1'" x-bind:data-lvl="o.lv" x-bind:style="'padding-inline-start:' + (12 + Math.max(0, o.lv - 1) * 16) + 'px'"
                            x-on:click="choose(o)">
                        <span class="min-w-0 flex-1 [overflow-wrap:anywhere]" x-text="o.name"></span>
                        <span x-show="o.k === $wire.parentUlid" class="inline-flex shrink-0 text-primary-text" aria-hidden="true"><x-bs-admin::icon name="ri-check" :class="$glyph" /></span>
                        <span x-show="o.why" class="basis-full text-small font-normal text-text-muted" x-text="o.why"></span>
                    </button>
                </template>
            </div>
            <p x-show="more && ! busy" class="m-0 border-t border-border px-3 py-2 text-small text-text-muted">{{ __('entries.ed.par_more', ['n' => \App\Modules\Cms\Support\PageRules::WALK_SIZE]) }}</p>
        </div>
    </div>
    @error('parentUlid') <p id="entry-parent-e" class="{{ $error }}">{{ $message }}</p> @enderror
</div>
