{{--
    The page builder's preview frame when the preview was refused (404 / 413 / 419 / 422 / 429): the
    editor cannot read the status of a form it sent into a frame, so this tiny page says it instead.
    It holds no words (the editor shows the message in the member's language), names the frame's nonce
    only when the one sent was well formed (BuilderPreview::nonceOf), and loads the frame's script,
    which passes the code (and, for 429, the seconds to wait) to the editor. Same headers and CSP as a
    preview (EntryPreviewPage::headers).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
@if ($nonce !== null)
<meta name="bs-pb" content="{{ $nonce }}">
@endif
<meta name="bs-pb-error" content="{{ $code }}"@if ($retry !== null) data-retry="{{ $retry }}"@endif>
@vite(['resources/js/sf-builder-bridge.js'])
</head>
<body></body>
</html>
