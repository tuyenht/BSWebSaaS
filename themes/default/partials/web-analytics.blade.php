{{-- The site's Cloudflare Web Analytics beacon (WebAnalytics): printed once, last in the body, only when the site has a well-formed token and never in the admin's preview. --}}
@if (\App\Modules\Theme\Chrome\WebAnalytics::valid($webAnalytics ?? null))
<script defer src="{{ \App\Modules\Theme\Chrome\WebAnalytics::BEACON_SRC }}" data-cf-beacon='{!! \App\Modules\Theme\Chrome\WebAnalytics::beaconConfig($webAnalytics) !!}'></script>
@endif
