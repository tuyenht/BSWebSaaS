@props(['html', 'text' => ''])
{{-- A «Mẫu thư» template's body (MailTemplateRenderer::html): cleaned HTML with the values filled, on one line so Markdown passes it through. --}}
<div class="template-body">{!! $html !!}</div>
