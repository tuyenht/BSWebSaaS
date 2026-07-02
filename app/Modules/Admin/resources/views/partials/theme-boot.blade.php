{{--
    Head boot for the admin theme. Synchronous ON PURPOSE and placed before the
    stylesheet so it runs pre-paint: it re-applies a PENDING per-user change
    (localStorage buffer, written by the launcher and cleared once the POST lands)
    over the SSR attributes — covering the "toggle then reload before the save
    finished" race. The DB stays the source of truth; a stale mirror can never
    override it because only unsaved writes live in the buffer.

    Axes come from AdminChromeResolver per plane (the platform console is
    scheme-only) and values are allow-listed here too — localStorage is
    user-editable, nothing outside the enum may reach <html>.

    The admin planes carry no CSP (unlike the storefront), so inline is fine.
--}}
@php
    $planeFields = \App\Modules\Admin\Support\AdminPlane::isPlatform()
        ? ['scheme']
        : array_keys(\App\Modules\Admin\Theme\AdminChromeResolver::FIELDS);
    $axes = [];
    foreach ($planeFields as $field) {
        $axes[$field] = [
            'attr' => \App\Modules\Admin\Theme\AdminChromeResolver::ATTRIBUTES[$field],
            'values' => \App\Modules\Admin\Theme\AdminChromeResolver::FIELDS[$field],
        ];
    }
@endphp
<script>
    window.__adminTheme = {
        axes: @js($axes),
        saveUrl: @js(\App\Modules\Admin\Support\AdminPlane::route('theme.save')),
        resetUrl: @js(\App\Modules\Admin\Support\AdminPlane::route('theme.reset')),
    };
    (function () {
        try {
            var pending = JSON.parse(localStorage.getItem('bs-admin-theme-pending') || 'null');
            if (!pending || typeof pending !== 'object') return;
            var axes = window.__adminTheme.axes;
            Object.keys(axes).forEach(function (field) {
                var value = pending[field];
                if (typeof value === 'string' && axes[field].values.indexOf(value) !== -1) {
                    document.documentElement.setAttribute(axes[field].attr, value);
                }
            });
        } catch (e) { /* corrupt buffer → SSR attributes stand */ }
    })();
</script>
