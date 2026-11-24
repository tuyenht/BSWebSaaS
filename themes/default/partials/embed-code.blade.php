{{-- The website's extra embed code for one place ($embedSlot: head · body_top · body_end · foot),
     printed raw as its owner pasted it. Storefront\Support\SiteEmbedCode decides whether this
     response gets any: never for a visitor with a session, a preview, or a website whose switch
     is off. Only the content layouts include it — the 404, the website's state pages and
     maintenance never do. --}}
@php($sfEmbed = app(\App\Modules\Storefront\Support\SiteEmbedCode::class)->take($embedSlot))
@if ($sfEmbed !== '')
{!! $sfEmbed !!}
@endif
