{{--
    The site search box (a plain GET form to the search page, so it works without a script): a magnifier
    inside on the left, the text field, the "Tìm" button on the right. Used by the header's search panel
    ($close: the ✕ that closes the panel, after the box), the search page ($value: the words searched for)
    and the 404 page ($label: a visible label above the box instead of the field's own name; $small:
    the rounder, lower box). $id names the field. Every value is Blade-escaped.
    With search on, the page's $searchSuggest (PageChrome) is where the box asks for suggestions while
    the visitor types: the script (data-suggest-js, fetched on the first focus), its stylesheet and its
    texts ride on the form; without a script nothing changes.
--}}
@php
    $sfSuggest = ! empty($searchSuggest) && is_string($searchSuggest) ? $searchSuggest : null;
@endphp
<form class="sf-sform{{ ! empty($close) ? ' sf-sform--panel' : '' }}" role="search" action="{{ $action }}" method="get"@if ($sfSuggest !== null) data-suggest="{{ $sfSuggest }}" data-suggest-js="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/sf-search-suggest.js') }}" data-suggest-css="{{ \Illuminate\Support\Facades\Vite::asset('resources/css/sf-search.css') }}" data-suggest-text="{{ json_encode([
    'label' => __('storefront.search.suggest_label'),
    'one' => __('storefront.search.suggest_one'),
    'count' => __('storefront.search.suggest_count'),
    'loading' => __('storefront.search.suggest_loading'),
    'all' => __('storefront.search.suggest_all'),
    'all_short' => __('storefront.search.suggest_all_short'),
    'all_n' => __('storefront.search.suggest_all_n'),
    'all_n_one' => __('storefront.search.suggest_all_n_one'),
    'all_n_short' => __('storefront.search.suggest_all_n_short'),
    'all_n_short_one' => __('storefront.search.suggest_all_n_short_one'),
    'none_title' => __('storefront.search.suggest_none_title'),
    'none_hint' => __('storefront.search.suggest_none_hint'),
    'none_hint_solo' => __('storefront.search.suggest_none_hint_solo'),
    'go' => __('storefront.search.go'),
    'select' => __('storefront.search.suggest_select'),
    'open' => __('storefront.search.suggest_open'),
    'close' => __('storefront.search.suggest_close'),
], JSON_UNESCAPED_UNICODE) }}"@endif>
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
