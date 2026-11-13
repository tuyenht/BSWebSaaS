{{--
    The page inside the "Nút liên hệ nổi" preview frame (<iframe srcdoc>): the website's own widget
    (theme::partials.floating-contact, its sf-floating.css and the site's colour tokens), so the admin
    shows exactly what the site prints. Everything below that is not the site's is the admin's own and
    lives only here: the page sketch behind the buttons, the light / dark page background
    (html[data-page], set by the admin page), the room the site keeps under its footer for the
    buttons (.sf-fc-ghost, .sf-fc-bar--ghost: the frame never scrolls, so they are left out) and the unfinished slot of a channel not filled in yet
    ([data-fc-pending]). Links never leave the frame; "Chép số" only shows its "copied" word for about
    two seconds; the open dial or "Thêm" sheet is reopened after the frame is redrawn (window.parent).
--}}
<!doctype html>
<html lang="{{ $lang }}" data-page="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/css/sf-floating.css') }}">
<style>:root{@foreach ($tokens as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>
<style>
html{font-family:var(--font,system-ui),system-ui,sans-serif;color:var(--color-text,#334155);background:var(--color-surface,#fff)}
body{margin:0;min-height:100vh;background:color-mix(in srgb,var(--color-text-strong,#0f172a) 4%,var(--color-surface,#fff))}
html[data-page="dark"] body{background:#0b1120}
html{overflow:hidden}
.sf-fc-ghost,.sf-fc-bar--ghost{display:none!important}
.fcp-page{display:flex;flex-direction:column;gap:12px;padding:20px}
.fcp-page>span{display:block;height:12px;border-radius:6px;background:color-mix(in srgb,var(--color-text-strong,#0f172a) 12%,transparent)}
.fcp-page>span:nth-child(1){width:55%;height:16px}.fcp-page>span:nth-child(2){width:88%}.fcp-page>span:nth-child(3){width:78%}
.fcp-page>span:nth-child(4){height:96px;border-radius:10px}.fcp-page>span:nth-child(5){width:70%}
html[data-page="dark"] .fcp-page>span{background:rgb(255 255 255 / 10%)}
[data-fc-pending]{--fc-fill:var(--color-surface,#fff);--fc-tint:transparent}
[data-fc-pending] .sf-fc__btn,[data-fc-pending] .sf-fc-bar__dot{box-sizing:border-box;border:2px dashed #878a99;box-shadow:none;outline:0;color:#5b6275}
[data-fc-pending] .sf-fc__z{fill:var(--color-surface,#fff)}
[data-fc-pending] .sf-fc-bar__label,[data-fc-pending] .sf-fc-sheet__l{color:#5b6275}
</style>
</head>
<body>
<div class="fcp-page" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
@include('theme::partials.floating-contact')
<script>
(function () {
    var host = window.parent, frame = window.frameElement;
    if (frame && frame.dataset.page) { document.documentElement.dataset.page = frame.dataset.page; }
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (a) { e.preventDefault(); }
        var copy = e.target.closest('[data-fc-copy]');
        if (copy) {
            var word = copy.querySelector('.sf-fc__copy-t'), was = word ? word.textContent : '', sr = copy.parentNode.querySelector('[role=status]');
            if (word) { word.textContent = copy.dataset.done; }
            if (sr) { sr.textContent = copy.dataset.done; }
            setTimeout(function () { if (word) { word.textContent = was; } if (sr) { sr.textContent = ''; } }, 2000);
        }
    });
    document.addEventListener('toggle', function (e) {
        if (e.target.id) { host.__fcPreviewOpen = e.newState === 'open' ? e.target.id : null; }
    }, true);
    var open = host.__fcPreviewOpen && document.getElementById(host.__fcPreviewOpen);
    if (open && open.showPopover) { try { open.showPopover(); } catch (err) {} }
})();
</script>
</body>
</html>
