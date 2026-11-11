{{--
    A post (news look). The band carries the breadcrumb, the main category's name, the page's single
    h1, the excerpt and the dates (published, and "updated" when the text changed on a later day);
    the cover photo below it is the page's main image (eager, high priority, srcset) with the
    library caption under it. The body is one shared prose column (.sf-prose--article). Previous
    and next are plain links within the main category (older, newer); up to three related posts
    from it follow as news cards. A side column (the table of contents on a wide screen, and the
    blocks of the `post_aside` slot) sits beside the body and the links when either has something;
    with neither, the body keeps its single column. The `after_post` slot's blocks close the page. A post whose body opens with a title band gets its h1 and
    breadcrumb from that band instead. Everything comes server-built from the controller; no id
    reaches the markup.
--}}
@php
    $sfPost = is_array($post ?? null) ? $post : [];
    $sfCover = is_array($sfPost['cover'] ?? null) ? $sfPost['cover'] : null;
    $sfOwnBand = ($leadBlock ?? null) !== 'page_header';
    // The job look of the band (layout "job"): the short facts and the badge come from the controller.
    $sfJobBand = (bool) ($sfJob ?? false);
    $sfFacts = $sfJobBand && is_array($sfPost['facts'] ?? null) ? $sfPost['facts'] : [];
    $sfBadge = $sfJobBand ? (string) ($sfPost['badge'] ?? '') : '';
    // A body section that already prints a high-priority image keeps the page at one.
    $sfCoverPriority = ($bodyHighImage ?? false) ? 'eager' : 'high';
    // The capability look (layout "capability" includes this one) has neither the neighbouring-post links, related
    // cards, the share bar nor the author line: the page closes after its own content.
    $sfPlain = (bool) ($sfPlain ?? false);
    $sfPrev = $sfPlain ? null : ($previous ?? null);
    $sfNext = $sfPlain ? null : ($next ?? null);
    $sfRelated = $sfPlain ? [] : ($related ?? []);
    $sfToc = $toc ?? [];
    $sfAside = trim((string) (($slotHtml ?? [])['post_aside'] ?? ''));
    // The news look: who wrote it and how long it takes (under the title), a share bar beside the text and a reading
    // progress line at the top of the window. The author is the site's business (no staff name leaves the admin).
    $sfNews = ! $sfPlain && ! $sfJobBand;
    $sfShareUrl = $sfNews && ! empty($canonical) ? (string) $canonical : '';
    $sfAuthor = $sfNews ? trim((string) (($brand ?? [])['name'] ?? '')) : '';
    $sfMinutes = $sfNews ? \App\Modules\Theme\Components\ReadingTime::minutes((string) $sections) : 0;
    $sfInitials = $sfAuthor !== '' ? \App\Modules\Theme\Components\ReadingTime::initials($sfAuthor) : '';
    $sfRail = $sfShareUrl !== '';
    $sfHasAside = $sfAside !== '' || $sfToc !== [];
    $sfCols = $sfHasAside || $sfRail;
    $sfAfter = trim((string) (($slotHtml ?? [])['after_post'] ?? ''));
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}"{!! ($dir ?? 'ltr') === 'rtl' ? ' dir="rtl"' : '' !!}>
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
@if ($sfRail)
    <div class="post-progress" aria-hidden="true"><span class="post-progress__bar"></span></div>
@endif
    <main class="post-page{{ $sfPlain ? ' post-page--capability' : '' }}" id="noi-dung" tabindex="-1">
        <article class="post">
@if ($sfOwnBand)
            <header class="post-band{{ $sfJobBand ? ' post-band--job' : '' }}">
                <div class="post-band__inner">
                    @include('theme::partials.sf-breadcrumb', ['crumbs' => $crumbsShown ?? $breadcrumb ?? [], 'tone' => 'dark', 'class' => 'post-band__crumbs'])
@if ($sfJobBand)
                    <p class="post-band__tags">
                        <span class="post-band__open">{{ __('storefront.job_open') }}</span>
@if ($sfBadge !== '')
                        <span class="post-band__badge">{{ $sfBadge }}</span>
@endif
                    </p>
@else
@if (isset($category) && ! (($sfShownLast = collect($crumbsShown ?? $breadcrumb ?? [])->last()) !== null && ($sfShownLast['href'] ?? null) === ($category['url'] ?? '') && empty($sfShownLast['current'])))
                    <p class="post-band__chip">{{ $category['name'] }}</p>
@endif
@endif
                    <h1 class="post-band__title">{{ $title }}</h1>
@if ($sfFacts !== [])
                    <ul class="post-band__facts">
@foreach ($sfFacts as $fact)
                        <li class="post-band__fact{{ $fact['tone'] === 'accent' ? ' post-band__fact--accent' : '' }}">{{ $fact['text'] }}</li>
@endforeach
                    </ul>
@endif
@if (($sfPost['excerpt'] ?? '') !== '')
                    <p class="post-band__excerpt">{{ $sfPost['excerpt'] }}</p>
@endif
@if (($sfPost['date'] ?? '') !== '' || $sfAuthor !== '' || $sfMinutes > 0)
                    <p class="post-band__meta">
@if ($sfAuthor !== '')
                        <span class="post-band__author"><span class="post-band__avatar" aria-hidden="true">{{ $sfInitials }}</span>{{ $sfAuthor }}</span>
@endif
@if (($sfPost['date'] ?? '') !== '')
                        <time datetime="{{ $sfPost['datetime'] }}">{{ $sfPost['date'] }}</time>
@if (($sfPost['updated'] ?? '') !== '')
                        <span>{{ __('storefront.updated') }} <time datetime="{{ $sfPost['updatedDatetime'] }}">{{ $sfPost['updated'] }}</time></span>
@endif
@endif
@if ($sfMinutes > 0)
                        <span class="post-band__reading">{{ __('storefront.reading_minutes', ['count' => $sfMinutes]) }}</span>
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
@if ($sfCols)
            <div class="post-columns sf-container{{ $sfRail ? ' post-columns--share' : '' }}">
@if ($sfRail)
                @include('theme::partials.post-share', ['url' => $sfShareUrl, 'rail' => true])
@endif
                <div class="post-columns__main">
@if ($sfToc !== [])
                    <details class="post-toc-phone">
                        <summary class="post-toc-phone__summary">{{ __('storefront.toc_article') }}</summary>
                        <ol class="post-toc-phone__list">
@foreach ($sfToc as $item)
                            <li><a class="post-toc__link" href="#{{ $item['id'] }}">{{ $item['label'] }}</a></li>
@endforeach
                        </ol>
                    </details>
@endif
@endif
            <div class="post__body sf-prose sf-prose--article">{!! $sections !!}</div>
@if ($sfRail)
            @include('theme::partials.post-share', ['url' => $sfShareUrl, 'rail' => false])
@endif
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
@if ($sfCols)
                </div>
@if ($sfHasAside)
                @include('theme::partials.post-aside', ['toc' => $sfToc, 'html' => $sfAside, 'sticky' => $sfPlain])
@endif
            </div>
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
@if ($sfAfter !== '')
        <div class="slot-band">{!! $sfAfter !!}</div>
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
