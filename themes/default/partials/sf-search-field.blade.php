{{--
    The site search box (a plain GET form to the search page, so it works without a script): a magnifier
    inside on the left, the text field, the "Tìm" button on the right. Used by the header's search panel
    ($close: the ✕ that closes the panel, after the box), the search page ($value: the words searched for)
    and the 404 page ($label: a visible label above the box instead of the field's own name; $small:
    the rounder, lower box). $id names the field. Every value is Blade-escaped.
--}}
<form class="sf-sform{{ ! empty($close) ? ' sf-sform--panel' : '' }}" role="search" action="{{ $action }}" method="get">
@if (! empty($label))
    <label class="sf-sform__label" for="{{ $id }}">{{ $label }}</label>
@endif
    <div class="sf-sfield{{ ! empty($small) ? ' sf-sfield--s' : '' }}">
        <svg class="sf-sfield__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
        <input class="sf-sfield__in" id="{{ $id }}" type="search" name="q" value="{{ $value ?? '' }}" placeholder="{{ $placeholder ?? __('storefront.search.placeholder') }}"@if (empty($label)) aria-label="{{ __('storefront.search.field') }}"@endif autocomplete="off" enterkeyhint="search" maxlength="{{ \App\Modules\Storefront\Search\SearchText::MAX_LENGTH }}"@if (! empty($close)) data-search-input @endif>
        <button type="submit" class="sf-sfield__go">{{ __('storefront.search.go') }}</button>
    </div>
@if (! empty($close))
    <button type="button" class="sf-sform__x" aria-label="{{ __('storefront.search.close') }}" data-search-close><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg><span class="sf-tip" aria-hidden="true">{{ __('storefront.search.close_tip') }}</span></button>
@endif
</form>
