@php
    // Mirrors Velzon .table: thead bottom-border, padded cells, row bottom-borders, hover row.
    // Auto-styles plain <thead>/<tbody>/<th>/<td> via descendant variants — the consumer
    // writes semantic table markup, no per-cell classes needed. fs-13 for dense data.
@endphp
<div class="overflow-x-auto">
    <table {{ $attributes->merge(['class' => 'w-full text-left align-middle fs-13 text-body-text [&_thead_th]:border-b [&_thead_th]:border-border-strong [&_thead_th]:px-3 [&_thead_th]:py-3 [&_thead_th]:font-semibold [&_thead_th]:text-body-muted [&_tbody_td]:border-b [&_tbody_td]:border-border [&_tbody_td]:px-3 [&_tbody_td]:py-3 [&_tbody_tr:last-child_td]:border-0 [&_tbody_tr:hover]:bg-surface-muted']) }}>
        {{ $slot }}
    </table>
</div>
