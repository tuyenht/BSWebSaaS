{{-- The site's Cloudflare Web Analytics token (WebAnalytics), as data only: storefront.js adds Cloudflare's beacon script after the page has loaded and the browser is idle, so it never counts against the load. Printed once, last in the body, only when the site has a well-formed token and never in the admin's preview. --}}
@if (\App\Modules\Theme\Chrome\WebAnalytics::valid($webAnalytics ?? null))
<div hidden data-sf-beacon='{!! \App\Modules\Theme\Chrome\WebAnalytics::beaconConfig($webAnalytics) !!}' data-src="{{ \App\Modules\Theme\Chrome\WebAnalytics::BEACON_SRC }}"></div>
@endif
