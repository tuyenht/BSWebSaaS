@props([
    'tabs' => [],
])
@php
    // Presentational-ish tabs: pass an assoc array ['key' => 'Label']; panels go in the default
    // slot as <div x-show="active === 'key'" role="tabpanel">…</div> (the slot renders inside
    // this x-data scope, so `active` is in scope). Arrow keys move between tabs.
    $keys = array_keys($tabs);
    $first = $keys[0] ?? null;
@endphp
<div x-data="{ active: @js($first), keys: @js($keys), move(d) { const i = this.keys.indexOf(this.active); this.active = this.keys[(i + d + this.keys.length) % this.keys.length]; } }"
     {{ $attributes }}>
    <div role="tablist" class="flex gap-1 border-b border-border">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab"
                    :aria-selected="active === @js($key) ? 'true' : 'false'"
                    :tabindex="active === @js($key) ? 0 : -1"
                    @click="active = @js($key)"
                    @keydown.right.prevent="move(1)" @keydown.left.prevent="move(-1)"
                    :class="active === @js($key) ? 'border-accent text-accent' : 'border-transparent text-body-muted hover:text-body-text'"
                    class="-mb-px inline-flex min-h-control items-center border-b-2 px-4 py-1 text-body font-medium transition-colors pointer-coarse:min-h-hit">
                {{ $label }}
            </button>
        @endforeach
    </div>
    <div class="pt-4">
        {{ $slot }}
    </div>
</div>
