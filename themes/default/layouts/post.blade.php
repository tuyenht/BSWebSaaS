{{--
    A post (news look). The band carries the breadcrumb, the main category's name, the page's single
    h1, the excerpt and the dates (published, and "updated" when the text changed on a later day);
    the cover photo below it is the page's main image (eager, high priority, srcset) with the
    library caption under it. The body is one shared prose column (.sf-prose--article). Previous
    and next are plain links within the main category (older, newer); up to three related posts
    from it follow as news cards. A post whose body opens with a title band gets its h1 and
    breadcrumb from that band instead. Everything comes server-built from the controller; no id
    reaches the markup.
--}}
@php
    $sfPost = is_array($post ?? null) ? $post : [];
    $sfCover = is_array($sfPost['cover'] ?? null) ? $sfPost['cover'] : null;
    $sfOwnBand = ($leadBlock ?? null) !== 'page_header';
    // A body section that already prints a high-priority image keeps the page at one.
    $sfCoverPriority = ($bodyHighImage ?? false) ? 'eager' : 'high';
    $sfPrev = $previous ?? null;
    $sfNext = $next ?? null;
    $sfRelated = $related ?? [];
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="post-page" id="noi-dung" tabindex="-1">
        <article class="post">
@if ($sfOwnBand)
            <header class="post-band">
                <div class="post-band__inner">
                    @include('theme::partials.sf-breadcrumb', ['crumbs' => $breadcrumb ?? [], 'tone' => 'dark', 'class' => 'post-band__crumbs'])
@isset($category)
                    <p class="post-band__chip">{{ $category['name'] }}</p>
@endisset
                    <h1 class="post-band__title">{{ $title }}</h1>
@if (($sfPost['excerpt'] ?? '') !== '')
                    <p class="post-band__excerpt">{{ $sfPost['excerpt'] }}</p>
@endif
@if (($sfPost['date'] ?? '') !== '')
                    <p class="post-band__meta">
                        <time datetime="{{ $sfPost['datetime'] }}">{{ $sfPost['date'] }}</time>
@if (($sfPost['updated'] ?? '') !== '')
                        <span>{{ __('storefront.updated') }} <time datetime="{{ $sfPost['updatedDatetime'] }}">{{ $sfPost['updated'] }}</time></span>
@endif
                    </p>
@endif
                </div>
            </header>
@if ($sfCover !== null)
            <figure class="post-cover">
                @include('theme::partials.sf-img', ['src' => $sfCover['src'], 'srcset' => $sfCover['srcset'], 'sizes' => '(min-width:80rem) 78rem, calc(100vw - 2rem)', 'alt' => $sfCover['alt'], 'w' => $sfCover['width'], 'h' => $sfCover['height'], 'priority' => $sfCoverPriority, 'class' => 'post-cover__img'])
@if ($sfCover['caption'] !== '')
                <figcaption class="post-cover__caption">{{ $sfCover['caption'] }}</figcaption>
@endif
            </figure>
@endif
@endif
            {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). Post
                 bodies reuse the block-list Page-JSON (D1), so they render exactly like a page. --}}
            <div class="post__body sf-prose sf-prose--article">{!! $sections !!}</div>
@if ($sfPrev !== null || $sfNext !== null)
            <nav class="post-nav" aria-label="{{ __('storefront.post_nav') }}">
@if ($sfPrev !== null)
                <a class="post-nav__link post-nav__link--prev" href="{{ $sfPrev['url'] }}"><span class="post-nav__label">{{ __('storefront.prev_post') }}</span><span class="post-nav__title">{{ $sfPrev['title'] }}</span></a>
@endif
@if ($sfNext !== null)
                <a class="post-nav__link post-nav__link--next" href="{{ $sfNext['url'] }}"><span class="post-nav__label">{{ __('storefront.next_post') }}</span><span class="post-nav__title">{{ $sfNext['title'] }}</span></a>
@endif
            </nav>
@endif
        </article>
@if ($sfRelated !== [])
        <section class="post-related" aria-labelledby="bai-viet-lien-quan">
            <div class="sf-container">
                @include('theme::partials.sf-section-head', ['heading' => __('storefront.related_posts'), 'description' => '', 'headId' => 'bai-viet-lien-quan'])
                <ul class="sf-grid sf-grid--3m">
@foreach ($sfRelated as $card)
                    <li>@include('theme::partials.sf-card', ['card' => ['excerpt' => '', 'label' => ''] + $card, 'kind' => 'news', 'level' => 'h3'])</li>
@endforeach
                </ul>
            </div>
        </section>
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
