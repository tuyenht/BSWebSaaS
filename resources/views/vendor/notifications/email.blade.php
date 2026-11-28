{{-- The shared frame of every notification mail (Laravel's own, with a website's look when one is in context:
     its logo on top, buttons and the greeting in its colour, its signature at the foot — Admin\Support\MailBrand).
     The body is Markdown: keep each HTML part on its own lines, unindented, with no blank line inside it.
     A mail built from a «Mẫu thư» template (Admin\Support\Mail\MailTemplateMail) brings its own body:
     it takes the place of the greeting, the lines, the button and the closing words. --}}
@php($brand = app(\App\Modules\Admin\Support\MailBrand::class)->current())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
@if ($brand === null)
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
@else
<x-mail::brand-space />
@endif
</x-slot:header>

@if ($brand !== null)
<x-mail::brand-logo :brand="$brand" />

@endif
@isset($templateHtml)
<x-mail::template-body :html="$templateHtml" :text="$templateText ?? ''" />

@else
{{-- Greeting --}}
@if (! empty($greeting))
@if ($brand !== null)
<x-mail::brand-heading :color="$brand['color']">{{ $greeting }}</x-mail::brand-heading>
@else
# {{ $greeting }}
@endif
@else
@if ($level === 'error')
# @lang('Whoops!')
@elseif ($brand !== null)
<x-mail::brand-heading :color="$brand['color']">@lang('Hello!')</x-mail::brand-heading>
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
@if ($brand !== null && $level !== 'error')
<x-mail::brand-button :url="$actionUrl" :color="$brand['color']" :text="$brand['text']">
{{ $actionText }}
</x-mail::brand-button>
@else
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endif
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ $brand !== null && $brand['name'] !== '' ? $brand['name'] : config('app.name') }}
@endif
@endisset

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
@if ($brand === null)
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
</x-mail::footer>
@else
<x-mail::brand-signature :brand="$brand" />
@endif
</x-slot:footer>
</x-mail::layout>
