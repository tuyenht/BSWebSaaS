{{--
    Formatted text (ADR-19) — the ONE section that prints a prop without escaping. `html` was cleaned
    by RichTextSanitizer::cms on every write (the body casts), and BlockRenderer has already prepared it
    (RichHtml): library pictures carry server-built addresses, videos are click-to-load façades, and a
    value that does not look like sanitizer output was sanitized again. The storefront CSP is the
    second net.
--}}
@php
    $html = is_string($props['html'] ?? null) ? trim($props['html']) : '';
@endphp
@if ($html !== '')
<section class="sf-prose">
{!! $html !!}
</section>
@endif
