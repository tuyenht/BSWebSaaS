<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <article class="product">
        <h1 class="product__title">{{ $title }}</h1>
        @if (! empty($images))
            <div class="product__gallery">
                @foreach ($images as $image)
                    <img class="product__image" src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy">
                @endforeach
            </div>
        @endif
        {{-- $description is the product's pre-sanitized description HTML (HTMLPurifier at write time),
             rendered raw exactly like a post body's block HTML. --}}
        <div class="product__body">{!! $description !!}</div>
    </article>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
