{{-- «+ Thêm khối» of «Trình dựng»: the kinds a body may hold, by name (the host gives $availableTypes,
     $kinds and $glyph). In a content body, a block added to written text makes the document the
     layout's first block (addBlock). A host that offers shared blocks gives $sharedPicker: the list
     starts with «Khối dùng chung» (board 27), which opens «Chèn khối dùng chung» (shared-block-picker). --}}
<div x-data="{ adding: false }" x-on:bs-ref-placed.window="adding = false" class="flex flex-col gap-2">
    <button type="button" class="hit-area inline-flex min-h-(--spacing-control) items-center justify-center gap-1.5 rounded-lg border-[1.5px] border-dashed border-border-input bg-transparent px-3 text-small font-semibold text-primary-text transition-colors hover:bg-surface-hover"
            x-on:click="adding = ! adding" x-bind:aria-expanded="adding ? 'true' : 'false'" aria-expanded="false" aria-controls="block-add-list">
        <x-bs-admin::icon name="ri-add" :class="$glyph" />{{ __('entries.ed.add_block') }}
    </button>
    <div id="block-add-list" x-show="adding" style="display: none" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-3">
        <p class="m-0 text-small font-semibold text-text-strong">{{ __('entries.ed.add_pick') }}</p>
        <ul class="block-palette m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(13rem,1fr))] gap-2 p-0">
            @if ($sharedPicker ?? null)
                <li>
                    <button type="button" x-on:click="$dispatch('bs-ref-pick')" data-ref-open
                            class="hit-area flex min-h-(--spacing-control) w-full items-center gap-2.5 rounded-md border border-[color-mix(in_srgb,var(--color-primary-text)_35%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-primary-text)_4%,var(--color-surface))] px-2.5 py-1.5 text-start text-small transition-colors hover:bg-surface-hover">
                        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded bg-[color-mix(in_srgb,var(--color-primary-text)_12%,var(--color-surface))] text-primary-text" aria-hidden="true">
                            <x-bs-admin::icon name="ri-stack" class="size-4" />
                        </span>
                        <span class="flex min-w-0 flex-col">
                            <span class="font-semibold text-primary-text">{{ __('entries.block.reusable_ref') }}</span>
                            <span class="text-caption text-text-muted">{{ __('entries.ref.add_hint') }}</span>
                        </span>
                    </button>
                </li>
            @endif
            @foreach ($availableTypes as $availableType)
                <li>
                    <button type="button" wire:click="addBlock('{{ $availableType }}')" x-on:click="adding = false"
                            class="hit-area flex min-h-(--spacing-control) w-full items-center gap-2.5 rounded-md border border-border bg-surface px-2.5 py-1.5 text-start text-small text-text transition-colors hover:bg-surface-hover">
                        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded {{ $kinds[$availableType]['tint'] }}" aria-hidden="true">
                            <x-bs-admin::icon :name="$kinds[$availableType]['icon']" class="size-4" />
                        </span>
                        <span class="min-w-0">{{ $kinds[$availableType]['label'] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</div>
