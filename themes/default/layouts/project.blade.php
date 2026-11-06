{{--
    A project post (the detail layout a category picks as "project"). The dark band holds the
    breadcrumb, the page's single h1 and the excerpt on the left and the cover photo on the right
    (the page's main image: eager, high priority, srcset); the body is the shared prose column; up to
    three other projects of the same category follow as project cards, with a link to the whole list.
    A post whose body opens with a title band gets its h1 and breadcrumb from that band instead.
    Everything comes server-built from the controller; no id reaches the markup.
--}}
@php
    $sfPost = is_array($post ?? null) ? $post : [];
    $sfCover = is_array($sfPost['cover'] ?? null) ? $sfPost['cover'] : null;
    $sfOwnBand = ($leadBlock ?? null) !== 'page_header';
    // A body section that already prints a high-priority image keeps the page at one.
    $sfCoverPriority = ($bodyHighImage ?? false) ? 'eager' : 'high';
    $sfRelated = $related ?? [];
    $sfAllUrl = isset($category) ? \App\Modules\Shared\Support\SafeLink::href($category['url'] ?? null) : null;
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="post-page post-page--project" id="noi-dung" tabindex="-1">
        <article class="post post--project">
@if ($sfOwnBand)
            <header class="post-band post-band--split">
                <div class="post-band__inner">
                    <div class="post-band__text">
                        @include('theme::partials.sf-breadcrumb', ['crumbs' => $breadcrumb ?? [], 'tone' => 'dark', 'class' => 'post-band__crumbs'])
                        <h1 class="post-band__title">{{ $title }}</h1>
@if (($sfPost['excerpt'] ?? '') !== '')
                        <p class="post-band__excerpt">{{ $sfPost['excerpt'] }}</p>
@endif
                    </div>
@if ($sfCover !== null)
                    <div class="post-band__media">
                        @include('theme::partials.sf-img', ['src' => $sfCover['src'], 'srcset' => $sfCover['srcset'], 'sizes' => '(min-width:80rem) 31rem, (min-width:1024px) 40vw, calc(100vw - 2rem)', 'alt' => $sfCover['alt'], 'w' => $sfCover['width'], 'h' => $sfCover['height'], 'priority' => $sfCoverPriority, 'class' => 'post-band__img'])
                    </div>
@endif
                </div>
            </header>
@endif
            {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). --}}
            <div class="post__body sf-prose sf-prose--article">{!! $sections !!}</div>
        </article>
@if ($sfRelated !== [])
        <section class="post-related post-related--project" aria-labelledby="du-an-khac">
            <div class="sf-container">
                <div class="post-related__head">
                    <div>
                        <span class="post-related__rule" aria-hidden="true"></span>
                        <h2 class="post-related__title" id="du-an-khac">{{ __('storefront.other_projects') }}</h2>
                    </div>
@if ($sfAllUrl !== null)
                    <a class="post-related__all" href="{{ $sfAllUrl }}">{{ __('storefront.view_all') }} <span aria-hidden="true">→</span></a>
@endif
                </div>
                <ul class="sf-grid sf-grid--3m">
@foreach ($sfRelated as $card)
                    <li>@include('theme::partials.sf-card', ['card' => $card, 'kind' => 'project', 'level' => 'h3'])</li>
@endforeach
                </ul>
            </div>
        </section>
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
