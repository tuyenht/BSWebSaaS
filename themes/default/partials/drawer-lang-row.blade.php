{{--
    One language of the phone menu's list: a 48px-tall link in the language's own name (and script direction), with its
    Vietnamese name small beneath when that says something new, the home-page hint where the page has no translation
    in it, and a check mark on the one in use. $language comes from StorefrontChrome::languages().
--}}
@php($sfGloss = $language['current'] ? null : \App\Modules\Theme\Chrome\StorefrontChrome::languageGloss($language['locale'], $language['name'], app()->getLocale()))
<li><a class="sf-lang-r" href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" dir="{{ $language['dir'] }}"@if ($language['current']) aria-current="true"@endif><span class="sf-lang-r__t"><span class="sf-lang-r__n">{{ $language['name'] }}</span>@if ($sfGloss !== null)<span class="sf-lang-r__g" lang="{{ app()->getLocale() }}">{{ $sfGloss }}</span>@endif @if ($language['fallback'])<small class="sf-drawer__hint">{{ __('storefront.lang_home') }}</small>@endif</span>@if ($language['current'])<span class="sf-lang-r__ck" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'check', 'size' => 20])</span>@endif</a></li>
