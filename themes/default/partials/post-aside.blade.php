{{--
    The side column of a post: the table of contents (wide screens only; `toc` = the body's h2
    headings that carry an id, two or more) and the pre-rendered blocks of the `post_aside` slot
    (`html`, already Blade-escaped by BlockRenderer). Only drawn when either has something. On a
    phone a column holding just the contents is hidden: the contents are a wide-screen help.
--}}
<aside class="post-aside{{ ($html ?? '') === '' ? ' post-aside--toc-only' : '' }}{{ ($sticky ?? false) ? ' post-aside--sticky' : '' }}" aria-label="{{ __('storefront.post_aside') }}">
@if (($toc ?? []) !== [])
    <nav class="post-toc" aria-label="{{ __('storefront.toc') }}">
        <p class="post-toc__title">{{ __('storefront.toc') }}</p>
        <ol class="post-toc__list">
@foreach ($toc as $item)
            <li><a class="post-toc__link" href="#{{ $item['id'] }}">{{ $item['label'] }}</a></li>
@endforeach
        </ol>
    </nav>
@endif
@if (($html ?? '') !== '')
    <div class="post-aside__blocks">{!! $html !!}</div>
@endif
</aside>
