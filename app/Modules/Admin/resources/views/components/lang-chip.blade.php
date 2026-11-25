@props(['code' => null, 'x' => null])
{{-- The language a field is written in, beside its label, when the site has several (board 10 .lc): a small
     outlined chip with the language's code. $code: a fixed language; $x: an Alpine expression giving the
     code, for a form that switches between its language versions (the categories drawer); give $code
     too (the form's main language), so the chip reads it before Alpine starts instead of an empty box.
     The caller shows it only when the site has more than one language. --}}
<span {{ $attributes->class('inline-flex min-h-5.5 shrink-0 items-center gap-1 rounded-sm border border-border-strong bg-surface px-1.5 align-middle text-caption leading-none font-bold text-text-strong') }}
      title="{{ __('admin.seo.locale_chip_title') }}" data-lang-chip @if ($x !== null) x-text="String({{ $x }}).toUpperCase()" @endif>{{ $code !== null ? strtoupper($code) : '' }}</span>
