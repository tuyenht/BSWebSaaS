{{--
    An admin error page inside the admin frame (design board 25, the "Trong khung quản trị" frames):
    the menu and the top bar stay, the state takes the content area. Only for a signed-in member who
    passed this request's membership check (AdminErrorPages decides).
--}}
@component($layout, ['title' => $page['title']])
    <div class="te-body te-in">
        @include('admin::errors.partials.view', ['page' => $page])
    </div>
    <div class="sr-only" aria-live="polite" data-te-live></div>
@endcomponent
