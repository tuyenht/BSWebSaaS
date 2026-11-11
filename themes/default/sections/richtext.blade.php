@php
    // A line break stays a line break and text between two pairs of asterisks is bold when the pair closes in the same line;
    // every piece is escaped, nothing else is read as markup.
    $sfParagraphs = [];
    foreach ((array) ($props['paragraphs'] ?? []) as $sfParagraph) {
        $sfLines = [];
        foreach (preg_split('/\R/u', is_scalar($sfParagraph) ? (string) $sfParagraph : '') ?: [] as $sfLine) {
            // Only a pair closed inside the line is bold; a lone ** stays as typed.
            $sfParts = explode('**', $sfLine);
            $sfClosed = count($sfParts) - (count($sfParts) % 2 === 0 ? 1 : 0);
            $sfPieces = '';
            foreach ($sfParts as $sfIndex => $sfPart) {
                if ($sfIndex >= $sfClosed && $sfIndex > 0) {
                    $sfPieces .= '**'.e($sfPart);
                } else {
                    $sfPieces .= $sfIndex % 2 === 1 ? '<strong>'.e($sfPart).'</strong>' : e($sfPart);
                }
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
