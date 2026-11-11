{{--
    The share bar of a news post: Zalo, Facebook, LinkedIn and "copy the link". Drawn twice by the
    layout: `rail` (a sticky column beside the text, wide screens) and a row after the text (phones);
    the stylesheet shows one of them. The three network links are plain links to the network's own share page
    with the post's address; the copy button is hidden until sf-post.js reveals it (without the script it
    would do nothing). `url` is the post's canonical address.
--}}
@php
    $sfEnc = rawurlencode((string) $url);
    $sfLinks = [
        ['zalo', 'https://sp.zalo.me/plugins/share?dev=1&url='.$sfEnc, __('storefront.share_zalo')],
        ['facebook', 'https://www.facebook.com/sharer/sharer.php?u='.$sfEnc, __('storefront.share_facebook')],
        ['linkedin', 'https://www.linkedin.com/sharing/share-offsite/?url='.$sfEnc, __('storefront.share_linkedin')],
    ];
@endphp
<div class="post-share post-share--{{ $rail ? 'rail' : 'row' }}" role="group" aria-label="{{ __('storefront.share_article') }}">
    <span class="post-share__label">{{ $rail ? __('storefront.share_label') : __('storefront.share_article') }}</span>
@foreach ($sfLinks as [$sfNetwork, $sfHref, $sfName])
    <a class="post-share__btn post-share__btn--{{ $sfNetwork }}" href="{{ $sfHref }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $sfName }}">
@if ($sfNetwork === 'zalo')
        <span aria-hidden="true">Zalo</span>
@elseif ($sfNetwork === 'facebook')
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/></svg>
@else
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
@endif
    </a>
@endforeach
    <button type="button" class="post-share__btn post-share__copy" data-url="{{ $url }}" data-done="{{ __('storefront.link_copied') }}" aria-label="{{ __('storefront.copy_link') }}" hidden>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
    </button>
    <span class="post-share__status" role="status"></span>
</div>
