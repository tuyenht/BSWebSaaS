@php
    // A line break stays a line break and text between two pairs of asterisks is bold;
    // every piece is escaped, nothing else is read as markup.
    $sfParagraphs = [];
    foreach ((array) ($props['paragraphs'] ?? []) as $sfParagraph) {
        $sfLines = [];
        foreach (preg_split('/\R/u', is_scalar($sfParagraph) ? (string) $sfParagraph : '') ?: [] as $sfLine) {
            $sfPieces = '';
            foreach (explode('**', $sfLine) as $sfIndex => $sfPart) {
                $sfPieces .= $sfIndex % 2 === 1 ? '<strong>'.e($sfPart).'</strong>' : e($sfPart);
            }
            $sfLines[] = $sfPieces;
        }
        $sfParagraphs[] = new \Illuminate\Support\HtmlString(implode('<br>', $sfLines));
    }
@endphp<section class="richtext">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    @foreach ($sfParagraphs as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
</section>
