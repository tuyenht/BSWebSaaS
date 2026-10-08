{{--
    The platform's admin brand colour as ONE :root block (AdminBrandColor, from the table in
    resources/admin-brands.json), over the build-time defaults of app.css @theme. A <style> block,
    never a style attribute on <html>: an inline style would beat the dark scheme's own values.
    Every value is a #rrggbb colour checked by AdminBrandColor; the admin planes carry no CSP.
--}}
<style>:root{@foreach (($brandTokens ?? []) as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>
