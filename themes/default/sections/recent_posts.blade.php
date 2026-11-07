{{--
    "Bài viết mới" in a side column (a block slot only): the newest posts of the post's root
    category branch, never the one being read or its related posts. Cards come server-built from the
    page (`$sfSlot['recent']`, PostDetail); nothing is drawn without any. Titles wrap (two lines),
    never cut by an ellipsis in the markup.
--}}
@php($sfRecent = is_array($sfSlot['recent'] ?? null) ? $sfSlot['recent'] : [])
@if ($sfRecent !== [])
<section class="slot-recent" aria-labelledby="slot-recent-title">
    <h2 class="slot-title" id="slot-recent-title">{{ ($props['heading'] ?? '') !== '' ? $props['heading'] : __('storefront.recent_posts') }}</h2>
    <ul class="slot-recent__list">
@foreach ($sfRecent as $sfPost)
@php($sfHref = \App\Modules\Shared\Support\SafeLink::href($sfPost['url'] ?? null))
        <li>
            <a class="slot-recent__item" @if ($sfHref !== null) href="{{ $sfHref }}"@endif>
@if (($sfPost['image'] ?? '') !== '')
                @include('theme::partials.sf-img', ['src' => $sfPost['image'], 'srcset' => $sfPost['srcset'] ?? null, 'sizes' => '96px', 'alt' => '', 'w' => 96, 'h' => 64, 'class' => 'slot-recent__img'])
@endif
                <span class="slot-recent__text">
                    <span class="slot-recent__name">{{ $sfPost['title'] }}</span>
@if (($sfPost['date'] ?? '') !== '')
                    <time class="slot-recent__date" datetime="{{ $sfPost['datetime'] }}">{{ $sfPost['date'] }}</time>
@endif
                </span>
            </a>
        </li>
@endforeach
    </ul>
</section>
@endif
